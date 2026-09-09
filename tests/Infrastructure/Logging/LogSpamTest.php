<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Logging;

use App\Domain\Exception\OAuthGrantRevokedException;
use App\Infrastructure\Doctrine\Logging\DoctrineLogHandler;
use Doctrine\DBAL\Connection;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * The two faults behind five hundred identical CRITICAL rows.
 *
 * A revoked Google sign-in produced one of these every fifteen minutes for as
 * long as the account stayed disconnected, and each one also left a dead job on
 * the failure transport. Neither was a fault in this application: the grant was
 * gone, the account was already flagged, and the interface already had a red
 * card with a Reconnect button on it. What the log added was volume.
 *
 * Both exceptions involved already implement UnrecoverableExceptionInterface,
 * and both docblocks say that was meant to fix exactly this. It only ever fixed
 * half — the retry ladder — because Messenger logs and files an escaped handler
 * exception regardless of whether it will retry it. The other half is not
 * letting it escape, which is what {@see SyncAccountMessageHandler} and
 * {@see SyncCalendarHandler} now do.
 *
 * The second fault is why the rows were unreadable rather than merely numerous.
 */
final class LogSpamTest extends TestCase
{
    /**
     * A stored row reads as a sentence, not as a template.
     *
     * Messenger's own message is `Error thrown while handling message {class}.
     * Removing from transport after {retryCount} retries. Error: "{error}"`.
     * Stored raw, every occurrence is byte-identical and the log browser is a
     * wall of one sentence — the class, the count and the error each a click
     * away in the context column.
     */
    public function testPlaceholdersAreFilledInBeforeTheRowIsWritten(): void
    {
        $written = [];

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willReturnCallback(
            static function (string $table, array $row) use (&$written): int {
                $written[] = $row;

                return 1;
            },
        );

        $handler = new DoctrineLogHandler($connection);

        $handler->handle(new LogRecord(
            new \DateTimeImmutable(),
            'messenger',
            Level::Critical,
            'Error thrown while handling message {class}. Removing from transport after {retryCount} retries.',
            [
                'class'      => 'App\\Infrastructure\\Messaging\\Message\\SyncAccountMessage',
                'retryCount' => 0,
            ],
        ));

        self::assertCount(1, $written);
        self::assertSame(
            'Error thrown while handling message App\\Infrastructure\\Messaging\\Message\\SyncAccountMessage. '
            . 'Removing from transport after 0 retries.',
            $written[0]['message'],
        );

        // The context is not consumed by the substitution: the row still
        // carries the structured values, which is what anything reading these
        // programmatically wants.
        self::assertStringContainsString('SyncAccountMessage', (string) $written[0]['context']);
    }

    /**
     * The classification that was supposed to stop this in the first place.
     *
     * Both handlers now decide from it, so a change here — or a new permanent
     * failure that forgets the interface — is a change in what escapes into
     * Messenger, and it should fail here rather than be discovered as another
     * five hundred rows.
     */
    public function testADeadGrantIsClassifiedAsUnrecoverable(): void
    {
        $revoked = new OAuthGrantRevokedException('The sign-in for account 14 has been revoked or has expired.');

        self::assertInstanceOf(
            \Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface::class,
            $revoked,
            'a dead grant would be retried, which cannot help and logs four times instead of once',
        );
    }
}
