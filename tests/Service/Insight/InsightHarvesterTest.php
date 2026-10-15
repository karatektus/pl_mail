<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Insight\MailInsightRepository;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;
use App\Service\Insight\InsightExtractorRegistry;
use App\Service\Insight\InsightHarvester;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The harvester's three promises, held against the real schema — because all
 * three are exactly the kind that pass in a mock and fail on a constraint.
 *
 * Upsert: a carrier's five status mails are ONE row, refreshed. Dismissal:
 * the user's "stop showing me this" outlives every later upsert. And the
 * off-switch: an extractor a user disabled writes nothing for them, however
 * eagerly it supports() the mail. The extractors themselves are stubs here on
 * purpose — their parsing is their own tests' business; this file owns the
 * landing.
 */
final class InsightHarvesterTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox = $this->seedLabel('Inbox', \App\Domain\Enum\Mail\LabelRole::Inbox);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAFollowUpMailRefreshesTheRowInsteadOfDuplicatingIt(): void
    {
        $harvester = $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 123', '123', ['status' => 'in_transit']),
        ]));

        $first = $this->thread('shipped')->messages->first();
        $harvester->harvest($first);
        $this->em->flush();

        $harvester = $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 123', '123', ['status' => 'delivered']),
        ]));

        $second = $this->thread('delivered')->messages->first();
        $harvester->harvest($second);
        $this->em->flush();

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertCount(1, $rows, 'two mails about one parcel are one card');
        self::assertSame('delivered', $rows[0]->payload['status'], 'the newest statement wins');
        self::assertSame($second->id, $rows[0]->message?->id, 'the link follows the newest mail');
        self::assertSame('stub:123', $rows[0]->dedupeKey, 'the harvester scopes the key by extractor');
    }

    public function testTwoMailsAboutOneParcelInOneBatchAreOneRowRatherThanAFailedFlush(): void
    {
        // Both mails exist before either is harvested, and nothing is flushed
        // between them: that is a sync batch. ExtractInsightsStep harvests all
        // of it and flushes once, so the second mail cannot find the first
        // one's row in the table.
        $first  = $this->thread('shipped')->messages->first();
        $second = $this->thread('delivered')->messages->first();

        $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 123', '123', ['status' => 'in_transit']),
        ]))->harvest($first);

        $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 123', '123', ['status' => 'delivered']),
        ]))->harvest($second);

        $this->em->flush();

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertCount(1, $rows, 'two mails about one parcel in one batch are one card');
        self::assertSame('delivered', $rows[0]->payload['status'], 'the newest statement wins');
        self::assertSame($second->id, $rows[0]->message?->id, 'the link follows the newest mail');
    }

    /**
     * The backfill walks stored mail by id, and ids are not dates: the Graph
     * import stored a mailbox newest-first, so the mail about a parcel being
     * out for delivery has the LOWER id and is read before the one that
     * announced it. "The later draft wins" has to mean later by the mail's own
     * date, or a re-read turns every parcel's card back into its first status.
     */
    public function testAnOlderMailDoesNotOverwriteWhatANewerOneSaid(): void
    {
        $older = $this->thread('announced', lastMessageAt: '2026-10-08 21:15:24')->messages->first();
        $newer = $this->thread('out for delivery', lastMessageAt: '2026-10-09 11:50:17')->messages->first();

        self::assertSame(1, $this->harvestOne($newer, 'out_for_delivery'), 'the first mail writes the card');
        self::assertSame(0, $this->harvestOne($older, 'announced'), 'the older one writes nothing, and says so');

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertCount(1, $rows);
        self::assertSame('out_for_delivery', $rows[0]->payload['status'], 'the older mail must not turn the card back');
        self::assertSame($newer->id, $rows[0]->message?->id, 'and must not take the link to itself');
    }

    public function testTheNewerMailStillWinsWhenItIsReadSecond(): void
    {
        $older = $this->thread('announced', lastMessageAt: '2026-10-08 21:15:24')->messages->first();
        $newer = $this->thread('out for delivery', lastMessageAt: '2026-10-09 11:50:17')->messages->first();

        $this->harvestOne($older, 'announced');
        $this->harvestOne($newer, 'out_for_delivery');

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertCount(1, $rows);
        self::assertSame('out_for_delivery', $rows[0]->payload['status']);
        self::assertSame($newer->id, $rows[0]->message?->id);
    }

    public function testTwoMailsOfTheSameInstantLeaveTheLastReadAsTheWinner(): void
    {
        $first  = $this->thread('first', lastMessageAt: '2026-10-09 10:00:00')->messages->first();
        $second = $this->thread('second', lastMessageAt: '2026-10-09 10:00:00')->messages->first();

        $this->harvestOne($first, 'announced');
        $this->harvestOne($second, 'out_for_delivery');

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertSame('out_for_delivery', $rows[0]->payload['status'], 'no mail is older than the other, so the last read speaks');
        self::assertSame($second->id, $rows[0]->message?->id);
    }

    public function testAnOlderMailRefreshesACardWhoseOwnMailIsGone(): void
    {
        $older = $this->thread('announced', lastMessageAt: '2026-10-08 21:15:24')->messages->first();
        $newer = $this->thread('out for delivery', lastMessageAt: '2026-10-09 11:50:17')->messages->first();

        $this->harvestOne($newer, 'out_for_delivery');

        // A deleted mail does not un-ship the parcel: the card outlives it and
        // only loses its link (see MailInsight::$message).
        $card = $this->repository()->findOneByDedupe($this->account, 'stub:123');
        self::assertInstanceOf(MailInsight::class, $card);
        $card->message = null;
        $this->em->flush();

        $this->harvestOne($older, 'announced');

        $rows = $this->repository()->findBy(['account' => $this->account]);

        self::assertSame('announced', $rows[0]->payload['status'], 'with no mail to be older than, the draft is the only statement left');
        self::assertSame($older->id, $rows[0]->message?->id);
    }

    public function testDismissalOutlivesTheNextUpsert(): void
    {
        $draft = new InsightDraft(InsightKind::Parcel, 'DHL · 9', '9', []);
        $harvester = $this->harvester($this->stub([$draft]));

        $harvester->harvest($this->thread('shipped')->messages->first());
        $this->em->flush();

        $insight = $this->repository()->findOneByDedupe($this->account, 'stub:9');
        self::assertInstanceOf(MailInsight::class, $insight);

        $insight->dismissedAt = new \DateTimeImmutable();
        $this->em->flush();

        $harvester->harvest($this->thread('a follow-up')->messages->first());
        $this->em->flush();

        self::assertNotNull(
            $this->repository()->findOneByDedupe($this->account, 'stub:9')?->dismissedAt,
            "the carrier's enthusiasm must not resurrect a dismissed card",
        );
    }

    public function testADisabledExtractorWritesNothing(): void
    {
        $this->user->setSetting(User::SETTING_INSIGHTS_DISABLED, ['stub']);
        $this->em->flush();

        $harvester = $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 5', '5', []),
        ]));

        $written = $harvester->harvest($this->thread('shipped')->messages->first());
        $this->em->flush();

        self::assertSame(0, $written);
        self::assertCount(0, $this->repository()->findBy(['account' => $this->account]));
    }

    public function testOneThrowingExtractorCostsOnlyItsOwnFacts(): void
    {
        $throwing = new class implements InsightExtractorInterface {
            public static function key(): string
            {
                return 'broken';
            }

            public function icon(): string
            {
                return 'fa-solid fa-bug';
            }

            public function priority(): int
            {
                return 200;
            }

            public function supports(Message $message): bool
            {
                return true;
            }

            public function extract(Message $message): array
            {
                throw new \RuntimeException('unparseable');
            }
        };

        $harvester = $this->harvester(
            $throwing,
            $this->stub([new InsightDraft(InsightKind::Parcel, 'DHL · 7', '7', [])]),
        );

        $written = $harvester->harvest($this->thread('shipped')->messages->first());
        $this->em->flush();

        self::assertSame(1, $written, 'the broken extractor must not eat the working one');
    }

    /**
     * One harvest of one mail that states the parcel in the given status,
     * flushed.
     *
     * @return int how many insights it wrote
     */
    private function harvestOne(Message $message, string $status): int
    {
        $written = $this->harvester($this->stub([
            new InsightDraft(InsightKind::Parcel, 'DHL · 123', '123', ['status' => $status]),
        ]))->harvest($message);

        $this->em->flush();

        return $written;
    }

    private function harvester(InsightExtractorInterface ...$extractors): InsightHarvester
    {
        return new InsightHarvester(
            new InsightExtractorRegistry($extractors),
            $this->repository(),
            $this->em,
            new NullLogger(),
        );
    }

    /** @param list<InsightDraft> $drafts */
    private function stub(array $drafts): InsightExtractorInterface
    {
        return new class($drafts) implements InsightExtractorInterface {
            /** @param list<InsightDraft> $drafts */
            public function __construct(private readonly array $drafts)
            {
            }

            public static function key(): string
            {
                return 'stub';
            }

            public function icon(): string
            {
                return 'fa-solid fa-box';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(Message $message): bool
            {
                return true;
            }

            public function extract(Message $message): array
            {
                return $this->drafts;
            }
        };
    }

    private function repository(): MailInsightRepository
    {
        return static::getContainer()->get(MailInsightRepository::class);
    }
}
