<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\DTO\Mail\IngestedMessage;
use App\Domain\Enum\Mail\CategorySource;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ClassifyMailHandler;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use App\Infrastructure\Messaging\Message\ExtractInsightsMessage;
use App\Infrastructure\Messaging\Message\ProcessReadReceiptsMessage;
use App\Infrastructure\Messaging\Message\ProposeEventsMessage;
use App\Infrastructure\Messaging\Message\ReleaseHeldMailMessage;
use App\Repository\Ai\AiSettingsRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\LiveMailPriority;
use App\Service\Ai\PromptLibrary;
use App\Service\Mail\MessageCategorizer;
use App\Service\Ai\OllamaClient;
use App\Service\Mail\HeldMailReleaser;
use App\Service\Mail\PostIngestPipeline;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * What happens to a message between being fetched and being seen, end to end
 * through the real pipeline and its real steps.
 *
 * TWO PROMISES ARE PINNED HERE, and they pull in opposite directions:
 *
 *   · Fetching mail is all the fetching worker does. Everything that follows
 *     a message is queued somewhere other than `ingest`, old mail is not
 *     queued at all, and mail that has just arrived goes to a queue no import
 *     can be in front of.
 *
 *   · Mail that the assistant is going to sort is not shown in a tab until it
 *     has been — and is shown regardless once the wait runs out.
 *
 * The real container's pipeline rather than one built by hand, unlike
 * PostIngestPipelineTest: the steps are the subject.
 */
final class ClassificationHoldFlowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
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

    public function testNothingThatFollowsAMessageIsQueuedBesideTheFetching(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $this->ingest($this->message('fresh', '-1 hour'));

        self::assertSame([], $this->transport('ingest')->getSent(), 'ingest is for talking to a mail provider');
    }

    public function testMailThatHasJustArrivedGoesToTheLiveQueue(): void
    {
        $this->importFinished();

        $message = $this->message('fresh', '-1 hour');
        $this->ingest($message);

        $live = $this->classesOn('enrich_live');

        self::assertContains(ExtractEventsMessage::class, $live);
        self::assertContains(ExtractInsightsMessage::class, $live);
        self::assertContains(ProposeEventsMessage::class, $live);
        self::assertContains(ProcessReadReceiptsMessage::class, $live);
        self::assertSame([], $this->classesOn('enrich'), 'nothing of a finished account is behind an import');
    }

    /**
     * The same recent message, on an account still being imported: wanted,
     * not watched, and so on the shared queue rather than the live one.
     */
    public function testRecentMailOfAnImportGoesToTheSharedQueue(): void
    {
        $this->assistantSorts();
        // No importFinished(): the folder has never completed a sync.

        $message = $this->message('fresh', '-1 hour');
        $this->ingest($message);

        self::assertSame([], $this->classesOn('enrich_live'));
        self::assertContains(ClassifyMailMessage::class, $this->classesOn('enrich'));
        self::assertNull($message->categoryHeldAt, 'an import that held its mail would hide a mailbox behind a model');
        self::assertNotNull($message->thread?->category);
    }

    /**
     * The newsletter from 2019. It gets a row, a thread and the rules' answer
     * — and no job of any kind.
     */
    public function testOldMailIsStoredAndNothingElse(): void
    {
        $this->assistantSorts();

        $message = $this->message('ancient', '-3 years');
        $this->ingest($message);

        self::assertNotNull($message->thread);
        self::assertNotNull($message->category);

        foreach (['ingest', 'enrich_live', 'enrich', 'enrich_backlog'] as $queue) {
            self::assertSame([], $this->classesOn($queue), $queue . ' holds nothing for mail this old');
        }
    }

    public function testArrivingMailIsKeptOutOfTheTabsWhileTheAssistantSorts(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        self::assertTrue($message->isCategoryHeld());
        self::assertNotNull($message->category, 'the rules\' answer is kept: it is what a release without a verdict files under');
        self::assertNull($message->thread?->category, 'no category is how a thread says it is in no tab');

        // One message to an envelope, on the live queue, with the timed way
        // out queued behind it.
        $classify = $this->sentOn('enrich_live', ClassifyMailMessage::class);

        self::assertCount(1, $classify);
        self::assertSame([(int) $message->id], $classify[0]->getMessage()->messageIds);

        // On its own queue. NOT the live one, whose worker may be inside the
        // very model call that is taking too long when this falls due, and not
        // export, where it would share a worker with mail leaving.
        self::assertSame([], $this->sentOn('enrich_live', ReleaseHeldMailMessage::class));
        self::assertSame([], $this->sentOn('export', ReleaseHeldMailMessage::class));

        $release = $this->sentOn('release', ReleaseHeldMailMessage::class);

        self::assertCount(1, $release);
        self::assertSame([(int) $message->id], $release[0]->getMessage()->messageIds);

        $delay = $release[0]->last(DelayStamp::class);

        self::assertNotNull($delay);
        self::assertSame(AiSettings::DEFAULT_HOLD_MAX_SECONDS * 1000, $delay->getDelay(), 'the hold ends when its ceiling does');
    }

    public function testAReleaseFilesTheMailWhereTheRulesPutItAndOnlyOnce(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        $releaser = self::getContainer()->get(HeldMailReleaser::class);

        self::assertSame(1, $releaser->release([$message]));

        $this->em->refresh($message->thread);

        self::assertFalse($message->isCategoryHeld());
        self::assertNotNull($message->categoryReleasedAt);
        self::assertSame($message->category, $message->thread->category);

        // The timed release arriving after the verdict has already released
        // the message is the ordinary case, and must be a no-op.
        self::assertSame(0, $releaser->release([$message]));
    }

    public function testMailHeldTooLongIsFoundByTheSweep(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        $repository = self::getContainer()->get(MessageRepository::class);

        self::assertSame([], $repository->findHeldBefore(new DateTimeImmutable('-1 hour')));

        $found = $repository->findHeldBefore(new DateTimeImmutable('+1 minute'));

        self::assertCount(1, $found);
        self::assertSame($message->id, $found[0]->id);
    }

    /**
     * The model host is a box on somebody's network and may simply be off.
     * Held mail must come out anyway, at once, under the rules' answer. It
     * is stamped as asked, like any other unanswered message: the question was
     * put once and is not put again.
     */
    public function testAHostThatDoesNotAnswerReleasesTheMailUnderTheRules(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        self::getContainer()->get(ClassifyMailHandler::class)(new ClassifyMailMessage([(int) $message->id]));

        $this->em->refresh($message->thread);

        self::assertFalse($message->isCategoryHeld());
        self::assertNotNull($message->aiCategorisedAt);
        self::assertSame(MessageCategory::Primary, $message->thread->category);
    }

    /**
     * What Admin → AI shows beside the switch: how long holding delayed mail,
     * and how often the wait ran out with nothing to show for it.
     */
    public function testTheDelayIsMeasuredFromTheHoldToTheRelease(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $answered = $this->message('answered', '-1 minute');
        $timedOut = $this->message('timed-out', '-1 minute');

        $this->ingest($answered);
        $this->ingest($timedOut);

        $heldAt = new DateTimeImmutable('-1 minute');

        $answered->categoryHeldAt     = $heldAt;
        $answered->aiCategorisedAt    = $heldAt->modify('+2 seconds');
        $answered->categoryReleasedAt = $heldAt->modify('+2 seconds');

        $timedOut->categoryHeldAt     = $heldAt;
        $timedOut->categoryReleasedAt = $heldAt->modify('+10 seconds');

        $this->em->flush();

        $repository = self::getContainer()->get(MessageRepository::class);
        $stats      = $repository->holdDelayStats(new DateTimeImmutable('-1 hour'));

        self::assertSame(2, $stats['held']);
        self::assertSame(1, $stats['timedOut']);
        self::assertEqualsWithDelta(6.0, $stats['median'], 0.01);
        self::assertGreaterThan(9.0, $stats['p95']);

        self::assertSame(
            ['held' => 0, 'timedOut' => 0, 'median' => null, 'p95' => null],
            $repository->holdDelayStats(new DateTimeImmutable('+1 hour')),
            'no data is null, not zero: a median of zero would read as instant',
        );
    }

    /**
     * The wait ran out, the mail was shown under the rules' answer, and then
     * the assistant's answer arrived. It still decides — the same question,
     * asked once, moves the mail — and the release time is left alone, because
     * that pair of timestamps is the measurement.
     */
    public function testAnAnswerThatArrivesAfterTheWaitStillMovesTheMail(): void
    {
        $this->assistantSorts();
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        // The timed release got there first.
        self::getContainer()->get(HeldMailReleaser::class)->release([$message], new DateTimeImmutable('-30 seconds'));

        $this->em->refresh($message->thread);
        self::assertSame(MessageCategory::Primary, $message->thread->category);

        $releasedAt = $message->categoryReleasedAt;

        $this->handlerWithAModelThatSays('promotions')(new ClassifyMailMessage([(int) $message->id]));

        $this->em->refresh($message->thread);

        self::assertSame(MessageCategory::Promotions, $message->aiCategory);
        self::assertSame(MessageCategory::Promotions, $message->thread->category);
        // To the second: the column keeps no fraction, and the handler re-reads the row.
        self::assertSame($releasedAt?->getTimestamp(), $message->categoryReleasedAt?->getTimestamp());
    }

    public function testNothingIsHeldWhenAnAdministratorSwitchedHoldingOff(): void
    {
        $this->assistantSorts(hold: false);
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        self::assertNull($message->categoryHeldAt);
        self::assertNotNull($message->thread?->category);
        self::assertSame([], $this->sentOn('release', ReleaseHeldMailMessage::class));
    }

    /**
     * Somebody sorting by rules has an answer that is already final — no
     * verdict is ever consulted — so there is nothing to wait for.
     */
    public function testNothingIsHeldForSomebodyWhoSortsByRules(): void
    {
        $this->assistantSorts(source: CategorySource::Rules);
        $this->importFinished();

        $message = $this->message('fresh', '-1 minute');
        $this->ingest($message);

        self::assertNull($message->categoryHeldAt);
        self::assertSame(MessageCategory::Primary, $message->thread?->category);
    }

    /**
     * The real handler around a model host that answers.
     *
     * Built by hand because the container's OllamaClient is already in use by
     * the time a test body runs and cannot be swapped; everything but the
     * assistant is the container's own.
     */
    private function handlerWithAModelThatSays(string $answer): ClassifyMailHandler
    {
        $container = self::getContainer();

        $assistant = new AiAssistant(
            $container->get(AiSettingsRepository::class),
            new OllamaClient(
                new MockHttpClient(new MockResponse((string) json_encode(['message' => ['content' => $answer]]))),
                new NullLogger(),
            ),
            $container->get(AiCallRecorder::class),
            new NullLogger(),
        );

        return new ClassifyMailHandler(
            $container->get(MessageRepository::class),
            $container->get(MessageThreadRepository::class),
            $container->get(ContactRepository::class),
            $container->get(MessageCategorizer::class),
            $assistant,
            $container->get(AiPermissions::class),
            $container->get(PromptLibrary::class),
            $container->get(LiveMailPriority::class),
            $container->get(HeldMailReleaser::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function ingest(Message $message): void
    {
        self::getContainer()->get(PostIngestPipeline::class)->run(
            $this->account,
            [new IngestedMessage($message, $this->account, null)],
        );
    }

    /** @return list<class-string> */
    private function classesOn(string $queue): array
    {
        return array_map(
            static fn ($envelope): string => $envelope->getMessage()::class,
            $this->transport($queue)->getSent(),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function sentOn(string $queue, string $class): array
    {
        return array_values(array_filter(
            $this->transport($queue)->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof $class,
        ));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** The installation has the assistant sorting mail, and so does this person. */
    private function assistantSorts(CategorySource $source = CategorySource::Assistant, bool $hold = true): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:4b-instruct';
        $settings->categorisationEnabled = true;
        $settings->holdUntilClassified   = $hold;
        $settings->holdMaxSeconds        = AiSettings::DEFAULT_HOLD_MAX_SECONDS;

        $this->em->persist($settings);

        $this->user->categorySorting->source = $source->value;

        $this->em->flush();
    }

    /** What ends an IMAP import: every folder has completed a sync. */
    private function importFinished(): void
    {
        $this->mailbox->syncedAt = new DateTimeImmutable('-1 day');

        $this->em->flush();
    }

    /** Persisted and flushed, which is the pipeline's stated precondition. */
    private function message(string $slug, string $received): Message
    {
        $message = new Message();
        $message->account = $this->account;
        $message->mailbox = $this->mailbox;
        $message->subject = 'Hold fixture ' . $slug;
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->receivedAt = new DateTimeImmutable($received);
        $message->hasAttachments = false;
        $message->bodyHtml = '<p>hello</p>';
        $message->messageId = sprintf('<hold-%s-%s@example.test>', $slug, uniqid('', true));

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'hold-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Hold';
        $this->user->nameLast = 'Flow';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr = $this->user;
        $this->account->email = 'hold-fixture@example.test';
        $this->account->username = 'hold-fixture@example.test';
        $this->account->imapHost = 'localhost';
        $this->account->imapPort = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost = 'localhost';
        $this->account->smtpPort = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password = 'x';
        $this->account->authType = 'password';
        $this->account->isActive = true;
        $this->em->persist($this->account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $this->account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();
    }
}
