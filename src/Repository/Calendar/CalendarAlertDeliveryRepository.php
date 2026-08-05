<?php

declare(strict_types=1);

namespace App\Repository\Calendar;

use App\Domain\DTO\Calendar\DueAlert;
use App\Entity\Calendar\CalendarAlertDelivery;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarAlertDelivery>
 */
class CalendarAlertDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarAlertDelivery::class);
    }

    /**
     * Take ownership of one alert, or discover that somebody already has.
     *
     * **Raw DBAL because the whole point is `ON CONFLICT DO NOTHING`, which the
     * ORM cannot express.** The alternative shape — read, decide, insert —
     * loses the race the unique constraint exists to win: two sweeps running a
     * second apart both read nothing, both insert, and one of them gets a
     * constraint violation *after* it has already sent a push notification. A
     * single statement that either inserts or does not is the only version where
     * "did I send this?" and "am I allowed to send this?" are the same question.
     *
     * Returns true when this caller now owns the alert and must deliver it.
     * A false is not a failure; it is the second sweep being told the first one
     * has it.
     *
     * **The claim is never rolled back on a delivery failure, and that is the
     * deliberate trade.** A push service that answers 500 costs the user one
     * reminder. Un-claiming would cost them a notification that arrives again
     * every minute for an hour, and "your meeting starts in ten minutes" is
     * false by the second delivery anyway.
     *
     * Timestamps are written by the statement because the ORM's lifecycle
     * callbacks are not involved — see CalendarAlertDelivery.
     */
    public function claim(DueAlert $due, ?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new DateTimeImmutable();

        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO calendar_alert_delivery
                    (event_id, usr_id, alert_key, recurrence_id, trigger_at, created_at, updated_at)
                VALUES
                    (:eventId, :usrId, :alertKey, :recurrenceId, :triggerAt, :now, :now)
                ON CONFLICT (event_id, alert_key, recurrence_id) DO NOTHING
                SQL,
            [
                'eventId'      => $due->eventId,
                'usrId'        => $due->userId,
                'alertKey'     => $due->alert->key,
                'recurrenceId' => $due->recurrenceId->format('Y-m-d H:i:s'),
                'triggerAt'    => $due->triggerAt->format('Y-m-d H:i:s'),
                'now'          => $now->format('Y-m-d H:i:s'),
            ],
            [
                'eventId'      => ParameterType::INTEGER,
                'usrId'        => ParameterType::INTEGER,
                'alertKey'     => ParameterType::STRING,
                'recurrenceId' => ParameterType::STRING,
                'triggerAt'    => ParameterType::STRING,
                'now'          => ParameterType::STRING,
            ],
        );

        return 1 === $inserted;
    }

    /**
     * Drop records too old to be consulted again.
     *
     * Safe only because the sweep's lookback window bounds how far back a
     * trigger can still be claimed — see DueAlertReader::LOOKBACK. A cutoff
     * shorter than that window would let an alert fire twice, which is why the
     * number lives on the reader and is passed in rather than repeated here.
     *
     * Raw DBAL for the same reason CalendarEventOccurrenceRepository::
     * deleteForEvent() uses it: this is a bulk delete of rows with no behaviour
     * worth hydrating, and a year of a daily series is several hundred of them.
     *
     * @return int how many were removed
     */
    public function pruneBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM calendar_alert_delivery WHERE trigger_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
            ['cutoff' => ParameterType::STRING],
        );
    }
}
