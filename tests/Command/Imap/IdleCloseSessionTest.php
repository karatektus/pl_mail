<?php

declare(strict_types=1);

namespace App\Tests\Command\Imap;

use App\Command\Imap\ImapIdleCommand;
use PHPUnit\Framework\TestCase;

/**
 * Ending an IDLE session the server has already ended is not a failure.
 *
 * A server that closes the session shortly before the 29-minute re-issue
 * answers `DONE` with nothing, and the library throws. That used to take the
 * retry ladder as a failure that never reset, until ten in a row ended the
 * process with exit code 1.
 */
final class IdleCloseSessionTest extends TestCase
{
    public function testAServerThatAnswersDoneWithNothingIsNotAFailure(): void
    {
        $connection = new class {
            public bool $doneCalled = false;

            public function done(): void
            {
                $this->doneCalled = true;

                throw new \RuntimeException('empty response');
            }
        };
        $client = new class {
            public bool $disconnected = false;

            public function disconnect(): void
            {
                $this->disconnected = true;
            }
        };

        ImapIdleCommand::closeSession($connection, $client);

        self::assertTrue($connection->doneCalled);
        self::assertTrue($client->disconnected, 'the client is still closed after DONE failed');
    }

    public function testADisconnectThatFailsIsNotAFailureEither(): void
    {
        $connection = new class {
            public function done(): void
            {
            }
        };
        $client = new class {
            public function disconnect(): void
            {
                throw new \ErrorException('fwrite(): Broken pipe');
            }
        };

        ImapIdleCommand::closeSession($connection, $client);

        $this->addToAssertionCount(1);
    }
}
