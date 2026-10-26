<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging\Handler;

use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ClassifyMailHandler;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Bulk sorting stops at the age Admin → AI sets, and nothing else does (#208).
 *
 * The limit is applied where the work is HANDLED, not only where it is queued,
 * because the queue outlives the setting: on the day of the upgrade there are
 * already envelopes of old mail waiting on enrich_backlog, serialised by a
 * build that had never heard of a limit. Each test here therefore puts its
 * message through the real bus as though a worker had just taken it off a
 * named queue — a ReceivedStamp, which is what a worker adds — so the
 * middleware that tells the handler its queue is under test as well as the
 * handler.
 *
 * "Was it asked?" is read off `aiCallMs`, the time the call took. The model
 * host is an address that does not resolve, and since #231 a host that cannot
 * be reached leaves `aiCategorisedAt` empty — so the stamp no longer tells an
 * asked message from a skipped one here. The timing is written for every call
 * that was made, answered or not, and never for a message that was skipped.
 */
final class ClassifyMailAgeLimitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
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

    /** @return iterable<string, array{string}> */
    public static function bulkQueues(): iterable
    {
        yield 'the catch-up after an import' => [EnrichmentRouter::BACKLOG];
        yield 'an import' => [EnrichmentRouter::IMPORT];
    }

    /**
     * The newsletter from 2019, queued before the limit existed, costs nothing
     * when its turn comes — and is left unstamped, so it can still be asked
     * about on purpose.
     */
    #[DataProvider('bulkQueues')]
    public function testOldMailOnABulkQueueIsLeftToTheRulesRatherThanAsked(string $queue): void
    {
        $this->assistantSorts(backlogDays: 30);
        $id = $this->message('-90 days');

        $this->takeOff($queue, new ClassifyMailMessage([$id]));

        self::assertFalse($this->asked($id), 'mail past the limit must not be asked about');
        self::assertFalse($this->stamped($id), 'and must not be marked as asked, or --any-age could not find it');
    }

    #[DataProvider('bulkQueues')]
    public function testRecentMailOnABulkQueueIsStillAsked(string $queue): void
    {
        $this->assistantSorts(backlogDays: 30);
        $id = $this->message('-3 days');

        $this->takeOff($queue, new ClassifyMailMessage([$id]));

        self::assertTrue($this->asked($id));
    }

    /**
     * Mail that has just arrived is asked about however old its date says it
     * is: a delayed delivery is still somebody's new mail, and the limit is
     * about history.
     */
    public function testLiveMailIsAskedAboutHoweverOldItIsRatherThanLimited(): void
    {
        $this->assistantSorts(backlogDays: 1);
        $id = $this->message('-3 years');

        $this->takeOff(EnrichmentRouter::LIVE, new ClassifyMailMessage([$id]));

        self::assertTrue($this->asked($id));
    }

    public function testZeroMeansNoLimitRatherThanNothing(): void
    {
        $this->assistantSorts(backlogDays: 0);
        $id = $this->message('-3 years');

        $this->takeOff(EnrichmentRouter::BACKLOG, new ClassifyMailMessage([$id]));

        self::assertTrue($this->asked($id));
    }

    /**
     * The way past the limit: `app:ai:classify-backlog --any-age` puts its
     * envelopes on the same queue, flagged, and they are asked about.
     */
    public function testMailAskedForAtAnyAgeIsAskedAboutOnTheBacklogQueue(): void
    {
        $this->assistantSorts(backlogDays: 30);
        $id = $this->message('-3 years');

        $this->takeOff(EnrichmentRouter::BACKLOG, new ClassifyMailMessage([$id], anyAge: true));

        self::assertTrue($this->asked($id));
    }

    /**
     * Held mail is out of the tabs until something answers, so the limit never
     * reaches it — not even on a bulk queue, and however old its date.
     */
    public function testHeldMailIsAskedAboutEvenOnABulkQueue(): void
    {
        $this->assistantSorts(backlogDays: 30);
        $id = $this->message('-90 days', held: true);

        $this->takeOff(EnrichmentRouter::BACKLOG, new ClassifyMailMessage([$id]));

        self::assertTrue($this->asked($id));
    }

    /**
     * Invoked directly — ReclassifyRecentHandler does this for the "ask again"
     * button — there is no queue, and no limit.
     */
    public function testAHandlerCalledDirectlyIsNotLimited(): void
    {
        $this->assistantSorts(backlogDays: 30);
        $id = $this->message('-3 years');

        self::getContainer()->get(ClassifyMailHandler::class)(
            new ClassifyMailMessage([$id]),
        );

        self::assertTrue($this->asked($id));
    }

    /**
     * Through the real bus, as a worker would hand it over: the ReceivedStamp
     * keeps it off any transport and names the queue it came from.
     */
    private function takeOff(string $queue, ClassifyMailMessage $message): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message, [new ReceivedStamp($queue)]);
    }

    private function assistantSorts(int $backlogDays): void
    {
        $settings = $this->em->getRepository(AiSettings::class)->findOneBy([]) ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'qwen3:4b-instruct';
        $settings->categorisationEnabled = true;
        $settings->categoriseBacklogDays = $backlogDays;

        $this->em->persist($settings);
        $this->em->flush();
    }

    /** A message received $received ago, by id; held out of the tabs if $held. */
    private function message(string $received, bool $held = false): int
    {
        $message = new Message();
        $message->account = $this->mailbox->account;
        $message->mailbox = $this->mailbox;
        $message->subject = 'Age limit fixture';
        $message->fromAddress = 'somebody@elsewhere.test';
        $message->receivedAt = new DateTimeImmutable($received);
        $message->hasAttachments = false;
        $message->messageId = sprintf('<age-limit-%s@example.test>', uniqid('', true));
        $message->categoryHeldAt = true === $held ? new DateTimeImmutable() : null;

        $this->em->persist($message);
        $this->em->flush();

        return (int) $message->id;
    }

    /**
     * Read from the table rather than from the object, so the assertion is
     * about what was stored and not about an instance the handler shared.
     */
    private function asked(int $id): bool
    {
        return null !== $this->column($id, 'ai_call_ms');
    }

    private function stamped(int $id): bool
    {
        return null !== $this->column($id, 'ai_categorised_at');
    }

    private function column(int $id, string $column): mixed
    {
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM message WHERE id = :id', $column), ['id' => $id]);

        self::assertNotFalse($value, 'the fixture message is gone');

        return $value;
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'age-limit-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Age';
        $user->nameLast = 'Limit';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'age-limit-fixture@example.test';
        $account->username = 'age-limit-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->mailbox = new Mailbox();
        $this->mailbox->account = $account;
        $this->mailbox->name = 'INBOX';
        $this->mailbox->fullPath = 'INBOX';
        $this->mailbox->isSyncEnabled = true;
        $this->mailbox->isIdleEnabled = false;
        $this->em->persist($this->mailbox);

        $this->em->flush();
    }
}
