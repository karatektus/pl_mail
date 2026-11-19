<?php

declare(strict_types=1);

namespace App\Repository\Calendar\CalendarEvent;

use App\Domain\Enum\Calendar\SyncState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The colours one user's events carry, for the editor's "Often used" row
 * (#616).
 *
 * Counted off the events themselves rather than kept as a setting. A setting
 * would have to be bumped on every save by every writer that can set a colour
 * — the editor, a JMAP client, a pull from the phone — and would still start
 * empty for a user whose phone has been colouring their Radicale calendar for
 * years. The events already are the record of which colours this person uses.
 */
final readonly class CalendarEventColorRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The colour values on this user's events, most used first, ties to the
     * one used most recently.
     *
     * Raw DBAL because the grouping key is a path into the jsonb object, which
     * DQL cannot address. Lower-cased and trimmed in SQL so "Teal" from one
     * client and "teal" from another count as one colour, which is what they
     * are to every client that draws them. Rows on their way out (a local
     * delete the remote has not been told about yet) do not count: they are
     * gone as far as the user is concerned.
     *
     * Values come back as stored, unvalidated; the caller keeps the ones it can
     * draw.
     *
     * @return list<string>
     */
    public function mostUsed(int $userId, int $limit): array
    {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT lower(trim(e.jscalendar->>'color')) AS color
               FROM calendar_event e
              WHERE e.usr_id = :userId
                AND e.sync_state <> :deleted
                AND coalesce(trim(e.jscalendar->>'color'), '') <> ''
              GROUP BY 1
              ORDER BY count(*) DESC, max(e.updated_at) DESC, 1
              LIMIT :limit",
            [
                'userId'  => $userId,
                'deleted' => SyncState::PendingDelete->value,
                'limit'   => $limit,
            ],
            [
                'userId'  => ParameterType::INTEGER,
                'deleted' => ParameterType::STRING,
                'limit'   => ParameterType::INTEGER,
            ],
        );

        return array_values(array_map(strval(...), $rows));
    }
}
