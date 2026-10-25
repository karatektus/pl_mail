<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

/**
 * One logged-in POP3 session: what Pop3AccountSyncer asks of a server.
 *
 * An interface so the syncer can be driven against a mailbox held in memory.
 * The rules worth testing there — which messages count as stored, what may be
 * deleted, what happens to message numbers when a session ends — are the
 * syncer's, and a real socket adds nothing to them but a forked process.
 * Pop3ConnectionTest covers the wire.
 *
 * Message numbers belong to the session that issued them. After quit() has
 * committed deletions the server numbers its mailbox afresh, so a number from
 * one session must never be used in the next; UIDLs are what carry over.
 */
interface Pop3Session
{
    /**
     * @return list<Pop3Listing> in server order
     *
     * @throws Pop3Exception
     */
    public function list(): array;

    /**
     * @return array<int, string> message number => UIDL
     *
     * @throws Pop3Exception
     */
    public function uidl(): array;

    /**
     * The header block of one message.
     *
     * @throws Pop3Exception including when the server does not offer TOP
     */
    public function top(int $number): string;

    /**
     * The whole message, as the sender wrote it.
     *
     * @throws Pop3Exception
     */
    public function retrieve(int $number): string;

    /**
     * Mark for deletion; only quit() makes it real.
     *
     * @throws Pop3Exception
     */
    public function markDeleted(int $number, string $uidl): void;

    public function isMarkedDeleted(string $uidl): bool;

    /** End the session cleanly, which commits the deletion marks. */
    public function quit(): void;

    /** Hang up without QUIT, which rolls every mark back. */
    public function abort(): void;
}
