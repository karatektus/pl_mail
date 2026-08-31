<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use RuntimeException;

/**
 * A state token this server cannot answer, and the client must start over.
 *
 * Raised when a reader presents a token into calendar_change_log that is not a
 * sequence, sits ahead of anything ever recorded, or falls below what pruning
 * has retained. Each protocol has its own way of saying "start over": JMAP
 * answers `cannotCalculateChanges`, CalDAV a `403` carrying `valid-sync-token`,
 * after which the client does a full read. Both are recoverable, and neither is
 * an error in the sense of something being broken.
 *
 * Deliberately NOT CalendarResyncRequiredException, which reads as the obvious
 * fit and is the wrong hierarchy. That one belongs to CalendarSyncException —
 * failures met while plMail is the *client* of a remote calendar, where the
 * subclass tells a sync worker whether to stop, wait or start over, and where
 * the message ends up in Calendar::$lastSyncError for a person to read. This is
 * the opposite direction: plMail is the server, nothing is wrong with any
 * connection, and no worker is listening. Sharing the parent would put a
 * serving concern under a transport's retry strategy and eventually surface a
 * client's stale token as a sync failure in the settings list.
 */
final class CalendarStateTokenException extends RuntimeException
{
}
