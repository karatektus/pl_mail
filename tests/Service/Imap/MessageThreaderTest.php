<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Imap\MessageThreader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageThreaderTest extends TestCase
{
    private MessageThreader $threader;

    protected function setUp(): void
    {
        // Both methods under test are pure string handling — the collaborators
        // are stubs purely to satisfy the constructor and are never called.
        $this->threader = new MessageThreader(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageRepository::class),
            $this->createStub(MessageThreadRepository::class),
        );
    }

    #[DataProvider('normalizeSubjectCases')]
    public function testNormalizeSubject(?string $subject, string $expected): void
    {
        self::assertSame($expected, $this->threader->normalizeSubject($subject));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function normalizeSubjectCases(): iterable
    {
        yield 'null'            => [null, ''];
        yield 'plain'           => ['Project update', 'project update'];
        yield 're'              => ['Re: Project update', 'project update'];
        yield 'fwd'             => ['Fwd: Project update', 'project update'];
        yield 'repeated'        => ['Re: Re: Fwd: Project update', 'project update'];
        yield 'no space'        => ['Fwd:Fwd:Project update', 'project update'];
        yield 'spaced colon'    => ['RE : Project update', 'project update'];
        yield 'counted'         => ['Re[2]: Project update', 'project update'];
        yield 'german reply'    => ['AW: Projektstatus', 'projektstatus'];
        yield 'german forward'  => ['WG: Projektstatus', 'projektstatus'];
        yield 'mixed locale'    => ['AW: Re: Projektstatus', 'projektstatus'];
        yield 'surrounding ws'  => ['  Re: Project update  ', 'project update'];
        yield 'prefix in body'  => ['Rewriting the parser', 'rewriting the parser'];
    }

    /**
     * The subject column is TEXT, so normalisation must not cap length —
     * truncating here would silently merge two distinct long subjects.
     */
    public function testNormalizeSubjectDoesNotTruncateLongSubjects(): void
    {
        $subject = 'Re: ' . str_repeat('a', 4000);

        self::assertSame(str_repeat('a', 4000), $this->threader->normalizeSubject($subject));
    }

    #[DataProvider('replyPrefixCases')]
    public function testHasReplyPrefix(?string $subject, bool $expected): void
    {
        self::assertSame($expected, $this->threader->hasReplyPrefix($subject));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function replyPrefixCases(): iterable
    {
        yield 'reply'            => ['Re: Project update', true];
        yield 'lowercase reply'  => ['re: project update', true];
        yield 'forward'          => ['Fwd: Project update', true];
        yield 'german reply'     => ['AW: Projektstatus', true];
        yield 'german forward'   => ['WG: Projektstatus', true];
        yield 'counted reply'    => ['Re[2]: Project update', true];
        yield 'null'             => [null, false];
        yield 'empty'            => ['', false];
        yield 'plain'            => ['Project update', false];
        // The regression this gate exists for: notification subjects repeat
        // verbatim for years and must never merge into one another.
        yield 'amazon order'     => ['Ihre Amazon.de Bestellung', false];
        yield 'amazon shipped'   => ['Ihre Bestellung wurde versandt', false];
        yield 'prefix-like word' => ['Rewriting the parser', false];
        yield 'colon elsewhere'  => ['Reminder: standup at 9', false];
    }

    /**
     * The inbox tabs filter on the thread's category, not the message's, so a
     * thread has to pick its category up during the sync itself — previously it
     * kept the Primary it was created with until `app:backfill category` ran.
     */
    public function testNewThreadAdoptsTheCategoryOfItsFirstMessage(): void
    {
        $message = $this->message(MessageCategory::Promotions, '2026-07-28 10:00:00');

        $this->threaderWithThreadLookup(null)->assignThread($message, new Account());

        self::assertSame(MessageCategory::Promotions, $message->getThread()?->getCategory());
    }

    /**
     * Most-recent-wins, matching what MessageThreadRepository::recomputeCategoriesForAccount()
     * does in SQL: the newest message decides, an older one arriving late does not.
     */
    #[DataProvider('threadCategoryCases')]
    public function testThreadCategoryFollowsTheNewestMessage(
        string $incomingReceivedAt,
        MessageCategory $expected,
    ): void {
        $thread = new MessageThread()
            ->setMessageCount(1)
            ->setUnreadCount(0)
            ->setAttachmentCount(0)
            ->setCategory(MessageCategory::Updates)
            ->setLastMessageAt(new \DateTimeImmutable('2026-07-28 12:00:00'));

        $message = $this->message(MessageCategory::Promotions, $incomingReceivedAt);

        $this->threaderWithThreadLookup($thread)->assignThread($message, new Account());

        self::assertSame($expected, $thread->getCategory());
    }

    /**
     * @return iterable<string, array{string, MessageCategory}>
     */
    public static function threadCategoryCases(): iterable
    {
        yield 'newer message wins' => ['2026-07-28 13:00:00', MessageCategory::Promotions];
        yield 'older message loses' => ['2026-07-28 11:00:00', MessageCategory::Updates];
    }

    /**
     * Locally-composed drafts reach the threader uncategorised; the thread must
     * still end up with a usable category rather than null.
     */
    public function testUncategorisedMessageLeavesThreadOnPrimary(): void
    {
        $message = $this->message(null, '2026-07-28 10:00:00');

        $this->threaderWithThreadLookup(null)->assignThread($message, new Account());

        self::assertSame(MessageCategory::Primary, $message->getThread()?->getCategory());
    }

    /**
     * A sync batch threads every message it built and flushes once at the end,
     * so the repository — which only sees flushed rows — keeps answering null
     * for a conversation whose thread was created moments earlier in the same
     * batch. Each message used to make its own thread, and the second INSERT
     * blew up on uniq_message_thread_provider_key_account, failing the batch.
     */
    public function testMessagesOfOneProviderConversationShareASingleThread(): void
    {
        $account  = new Account();
        $threader = $this->threaderWithThreadLookup(null, managed: true);

        $first  = $this->message(MessageCategory::Primary, '2026-07-28 10:00:00');
        $second = $this->message(MessageCategory::Primary, '2026-07-28 11:00:00');

        $threader->assignThread($first, $account);
        $threader->assignThread($second, $account);

        self::assertNotNull($first->getThread());
        self::assertSame($first->getThread(), $second->getThread());
        self::assertSame(2, $first->getThread()?->getMessageCount());
    }

    /**
     * The cache holds entities, and the worker's entity manager is cleared
     * between messages — a thread it no longer manages must not be handed out.
     */
    public function testDetachedThreadIsNotReused(): void
    {
        $account  = new Account();
        $threader = $this->threaderWithThreadLookup(null, managed: false);

        $first  = $this->message(MessageCategory::Primary, '2026-07-28 10:00:00');
        $second = $this->message(MessageCategory::Primary, '2026-07-28 11:00:00');

        $threader->assignThread($first, $account);
        $threader->assignThread($second, $account);

        self::assertNotSame($first->getThread(), $second->getThread());
    }

    /**
     * Routes assignThread() down its first branch — the provider conversation id
     * — so the thread under test is the one the stub hands back (or a fresh one
     * when that is null), and no other collaborator is touched.
     *
     * `managed` is what the entity manager says about threads created earlier
     * in the same run: true while a batch is in flight, false once it has been
     * cleared.
     */
    private function threaderWithThreadLookup(?MessageThread $found, bool $managed = false): MessageThreader
    {
        $threadRepository = $this->createStub(MessageThreadRepository::class);
        $threadRepository->method('findOneByProviderThreadKeyForAccount')->willReturn($found);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn($managed);

        return new MessageThreader(
            $entityManager,
            $this->createStub(MessageRepository::class),
            $threadRepository,
        );
    }

    private function message(?MessageCategory $category, string $receivedAt): Message
    {
        return new Message()
            ->setProviderThreadKey('conversation-1')
            ->setSubject('Project update')
            ->setFromAddress('sender@example.com')
            ->setCategory($category)
            ->setReceivedAt(new \DateTimeImmutable($receivedAt));
    }
}
