<?php

declare(strict_types=1);

namespace App\Domain\Interface;

/**
 * A connection that a handler deadline may shut down from underneath its
 * reader.
 *
 * ImapDeadline's whole mechanism is "close the socket and let the code that
 * was reading it fail the way it always has". A webklex Client hands the
 * deadline a stream to shut down. A POP3 session is not a client and has no
 * getConnection(); this is the two-method shape that lets one alarm cover
 * both without the deadline knowing which protocol it is dealing with.
 *
 * Deliberately not an exception path. closeForDeadline() must not throw and
 * must leave the connection in a state where every later read fails normally
 * rather than repairing itself — see ImapDeadline's docblock for why closing
 * a socket is the effect and throwing from the signal handler is not.
 */
interface DeadlineClosable
{
    /**
     * Shut the connection down so the reader in progress fails at once.
     *
     * Shut down rather than closed outright: the class's own `finally` will
     * still try to close it, and some servers have a goodbye to read. Mirrors
     * ImapDeadline::expire(), which uses STREAM_SHUT_RDWR for the same reason.
     */
    public function closeForDeadline(): void;

    /** Whether there is still something to shut down. */
    public function isOpen(): bool;
}
