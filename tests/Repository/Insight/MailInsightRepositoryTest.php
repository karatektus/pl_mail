<?php

declare(strict_types=1);

namespace App\Tests\Repository\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\Mail\Message;
use App\Repository\Insight\MailInsightRepository;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which undated cards the radar offers, and on whose clock.
 *
 * An undated card — GitHub activity, a code whose mail states no expiry — has
 * no date of its own, so the radar measures how recent it is. The first version
 * measured the ROW: when the harvester created the card. That is the mail's age
 * only while the harvester runs as the mail arrives. The insights backfill
 * re-reads old mail and creates its cards NOW, so every card made from a
 * month-old notification looked a minute old and took a place among the newest
 * twenty for a week.
 *
 * A real database rather than doubles because the thing under test is the
 * query: the join, the COALESCE and the ordering are what can be wrong. Every
 * instant is fixed — `$now` is passed in, and nothing here reads the clock.
 */
final class MailInsightRepositoryTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    /** The "now" of every test: a Tuesday at noon, UTC. */
    private const string NOW = '2026-10-13 12:00:00';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
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

    public function testACardIsAsRecentAsItsMailAndNotAsItsRow(): void
    {
        // Window: seven days back from NOW, so from 2026-10-06 12:00:00.
        // Both cards were CREATED a minute ago — as a backfill would have —
        // and only the mails differ: 33 days old, and one day old.
        $this->card('github:old#1', mail: '2026-09-10 08:00:00', created: '2026-10-13 11:59:00');
        $this->card('github:new#2', mail: '2026-10-12 08:00:00', created: '2026-10-13 11:59:00');

        self::assertSame(
            ['stub:github:new#2'],
            $this->dedupeKeys($this->recent()),
            'the card made today from a month-old mail is not recent; the one from yesterday\'s mail is',
        );
    }

    public function testTheNewestMailSpeaksFirstWhateverOrderTheRowsWereMade(): void
    {
        // Created in the opposite order to the mails: the card of the OLDER
        // mail is the newer row, which is what a newest-first import produces.
        $this->card('github:older-mail#1', mail: '2026-10-10 08:00:00', created: '2026-10-13 11:00:00');
        $this->card('github:newer-mail#2', mail: '2026-10-12 08:00:00', created: '2026-10-13 09:00:00');

        self::assertSame(
            ['stub:github:newer-mail#2', 'stub:github:older-mail#1'],
            $this->dedupeKeys($this->recent()),
        );
    }

    public function testACardWhoseMailIsGoneIsAsRecentAsItsRow(): void
    {
        // Same dates for both rows; neither has a mail to be dated by, since a
        // deleted mail leaves the card with no link (MailInsight::$message).
        $this->card('github:gone-recent#1', mail: null, created: '2026-10-12 08:00:00');
        $this->card('github:gone-old#2', mail: null, created: '2026-09-01 08:00:00');

        self::assertSame(['stub:github:gone-recent#1'], $this->dedupeKeys($this->recent()));
    }

    /**
     * @return list<MailInsight>
     */
    private function recent(): array
    {
        return $this->repository()->recentUndatedForUser($this->user, new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }

    /**
     * @param list<MailInsight> $insights
     *
     * @return list<string>
     */
    private function dedupeKeys(array $insights): array
    {
        return array_map(static fn (MailInsight $i): string => $i->dedupeKey, $insights);
    }

    /**
     * One undated card, of a kind the radar lists, linked to a mail received at
     * `$mail` (or to none) and created at `$created`.
     */
    private function card(string $key, ?string $mail, string $created, InsightKind $kind = InsightKind::GithubPullRequest): MailInsight
    {
        $insight            = new MailInsight();
        $insight->account   = $this->account;
        $insight->kind      = $kind;
        $insight->title     = $key;
        $insight->extractor = 'stub';
        $insight->dedupeKey = 'stub:' . $key;
        $insight->payload   = [];
        $insight->message   = null === $mail ? null : $this->mailReceivedAt($mail);
        $insight->restoreCreatedAt(new DateTimeImmutable($created, new DateTimeZone('UTC')));

        $this->em->persist($insight);
        $this->em->flush();

        return $insight;
    }

    private function mailReceivedAt(string $at): Message
    {
        $message = $this->thread('mail of ' . $at, lastMessageAt: $at . ' UTC')->messages->first();
        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    private function repository(): MailInsightRepository
    {
        return static::getContainer()->get(MailInsightRepository::class);
    }
}
