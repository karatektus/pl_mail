<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Message;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * How long mail took to arrive, per account and slowest first, for the admin panel.
 */
class MessageArrivalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * How long mail took to get from the provider into plMail, per account.
     *
     * The gap between the provider accepting a message and the moment its row
     * was created. It is the number that says whether push is working: an
     * account on IMAP IDLE or Gmail push shows seconds, and one living on the
     * quarter-hourly poll shows minutes — with nothing else on any screen to
     * tell the two apart, since both "work".
     *
     * FROM provider_accepted_at, NOT received_at. This measured from the
     * message's own date until it was noticed that the date is the sender's:
     * a newsletter stamped 28 minutes before it was sent counted as 28 minutes
     * of plMail being slow. See ProviderAcceptTime. Mail with no accept time —
     * anything stored more than a week before that column existed — is not in
     * the figures at all, which is better than being in them wrongly.
     *
     * THE INBOX AND EVERYTHING ELSE, COUNTED APART. A provider announces what
     * lands in the inbox and nothing more, so spam and mail a filter files
     * elsewhere wait for the schedule by design. Mixed in, they put a
     * quarter-hour tail on an account whose push is perfect; the three delay
     * figures are therefore of inbox mail, and the rest gets a count and a
     * typical delay of its own. By where the mail was filed when it arrived,
     * not where it is now — see ArrivalPlace.
     *
     * ONLY MAIL THAT ARRIVED, not mail that was imported. A mailbox being
     * brought in is years of history stored today, and its "lag" would be
     * years. So a message counts only if it was stored within a day of being
     * accepted. The cost is that an outage longer than a day is invisible
     * here — which is the right way round for a latency figure, and an outage
     * that long is on the System page in red.
     *
     * And not the account's own outgoing copies, which are written by plMail
     * at send time and would report a flattering zero.
     *
     * Clamped at zero: the accept time is the provider's clock, and a clock a
     * few seconds fast is not negative latency.
     *
     * Driven from `account` so each account's messages are found through
     * idx_message_account_accepted_at rather than by reading the table.
     *
     * @return list<array{account: string, provider: string, push: bool, messages: int,
     *     median: float|null, p95: float|null, max: float|null,
     *     elsewhere: int, elsewhereMedian: float|null}>
     */
    public function arrivalLagByAccount(DateTimeImmutable $since): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT a.email AS account,
                   a.auth_type, a.oauth_provider, a.incoming_protocol, a.push_enabled,
                   COUNT(l.lag) FILTER (WHERE l.inbox) AS messages,
                   percentile_cont(0.5)  WITHIN GROUP (ORDER BY l.lag) FILTER (WHERE l.inbox) AS median,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY l.lag) FILTER (WHERE l.inbox) AS p95,
                   MAX(l.lag) FILTER (WHERE l.inbox) AS max,
                   COUNT(l.lag) FILTER (WHERE NOT l.inbox) AS elsewhere,
                   percentile_cont(0.5)  WITHIN GROUP (ORDER BY l.lag) FILTER (WHERE NOT l.inbox) AS elsewhere_median
              FROM account a
              LEFT JOIN LATERAL (
                  SELECT GREATEST(0, EXTRACT(EPOCH FROM (m.created_at - m.provider_accepted_at))) AS lag,
                         COALESCE(m.arrived_in, 'elsewhere') = 'inbox' AS inbox
                    FROM message m
                   WHERE m.account_id = a.id
                     AND m.provider_accepted_at >= :since
                     AND m.created_at <= m.provider_accepted_at + INTERVAL '1 day'
                     AND LOWER(COALESCE(m.from_address, '')) <> LOWER(COALESCE(a.email, ''))
              ) l ON true
             WHERE a.is_active = true
             GROUP BY a.id, a.email, a.auth_type, a.oauth_provider, a.incoming_protocol, a.push_enabled
             ORDER BY a.email
            SQL,
            ['since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return array_map(static fn (array $row): array => [
            'account'  => (string) $row['account'],
            // A password account is named by its protocol, so a POP3 or JMAP
            // account is not listed as IMAP (#528).
            'provider' => 'oauth2' === $row['auth_type'] && null !== $row['oauth_provider']
                ? (string) $row['oauth_provider']
                : (string) $row['incoming_protocol'],
            'push'     => true === (bool) $row['push_enabled'],
            'messages' => (int) $row['messages'],
            'median'   => null === $row['median'] ? null : (float) $row['median'],
            'p95'      => null === $row['p95'] ? null : (float) $row['p95'],
            'max'      => null === $row['max'] ? null : (float) $row['max'],
            'elsewhere'       => (int) $row['elsewhere'],
            'elsewhereMedian' => null === $row['elsewhere_median'] ? null : (float) $row['elsewhere_median'],
        ], $rows);
    }

    /**
     * The messages that took longest to arrive, slowest first — the rows
     * behind arrivalLagByAccount(), for the question a percentile cannot
     * answer: which ones, and what was different about them.
     *
     * Each row carries the three things that explain a delay. What brought it
     * in: a push, IDLE, the schedule. Where it was filed: fourteen minutes for
     * spam on the schedule is the design, and the same for inbox mail on an
     * account with push is the fault this page exists to show. And how long
     * the message had already existed before the provider had it — time no
     * mail client could have saved, and the usual reason mail FEELS late when
     * every figure above says seconds.
     *
     * The same mail as the summary, under the same three exclusions, for the
     * reasons given there.
     *
     * NOTHING ABOUT WHAT WAS IN THE MAIL, like holdDetails() and for its
     * reason: no subject and no sender, on a page an administrator reads
     * about mailboxes that are not theirs.
     *
     * @return list<array{account: string, acceptedAt: DateTimeImmutable, lag: float,
     *     underway: float|null, by: string|null, in: string|null}>
     */
    public function slowestArrivals(DateTimeImmutable $since, int $limit): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT a.email AS account,
                   m.provider_accepted_at,
                   m.arrived_by,
                   m.arrived_in,
                   GREATEST(0, EXTRACT(EPOCH FROM (m.created_at - m.provider_accepted_at))) AS lag,
                   GREATEST(0, EXTRACT(EPOCH FROM (m.provider_accepted_at - m.received_at))) AS underway
              FROM message m
              JOIN account a ON a.id = m.account_id
             WHERE a.is_active = true
               AND m.provider_accepted_at >= :since
               AND m.created_at <= m.provider_accepted_at + INTERVAL '1 day'
               AND LOWER(COALESCE(m.from_address, '')) <> LOWER(COALESCE(a.email, ''))
             ORDER BY lag DESC, m.id DESC
             LIMIT :limit
            SQL,
            ['since' => $since, 'limit' => $limit],
            ['since' => Types::DATETIME_IMMUTABLE, 'limit' => Types::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'account'    => (string) $row['account'],
            'acceptedAt' => new DateTimeImmutable((string) $row['provider_accepted_at']),
            'lag'        => (float) $row['lag'],
            'underway'   => null === $row['underway'] ? null : (float) $row['underway'],
            'by'         => null === $row['arrived_by'] ? null : (string) $row['arrived_by'],
            'in'         => null === $row['arrived_in'] ? null : (string) $row['arrived_in'],
        ], $rows);
    }
}
