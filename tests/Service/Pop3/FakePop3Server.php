<?php

declare(strict_types=1);

namespace App\Tests\Service\Pop3;

use App\Entity\Mail\Account;
use App\Infrastructure\Pop3\Pop3ConnectionFactory;
use App\Infrastructure\Pop3\Pop3Session;

/**
 * A POP3 mailbox held in memory, with the one behaviour that makes POP3 hard:
 * message numbers are handed out per session, in mailbox order, and a QUIT
 * that commits deletions renumbers whatever is left.
 *
 * Doubles as the connection factory, so a syncer built with it opens sessions
 * on this mailbox. Every command a session sends is written to $log, which is
 * what the tests count RETRs and DELEs from.
 */
final class FakePop3Server extends Pop3ConnectionFactory
{
    /** @var array<string, string> UIDL => raw message, in mailbox order */
    public array $messages = [];

    /** @var array<string, true> UIDLs whose RETR the server answers with -ERR */
    public array $refuse = [];

    /** Whether TOP is offered (RFC 1939 makes it optional). */
    public bool $offersTop = true;

    /** @var list<string> every command, as "RETR uidl", "TOP uidl", "DELE uidl", "QUIT" */
    public array $log = [];

    /** How many sessions have been opened. */
    public int $sessions = 0;

    /** @var (callable(self, int): void)|null run as each session opens, with its ordinal */
    public $onOpen = null;

    public function __construct()
    {
        // No deadline: nothing here blocks.
    }

    public function add(string $uidl, string $raw): void
    {
        $this->messages[$uidl] = $raw;
    }

    public function open(Account $account): Pop3Session
    {
        ++$this->sessions;

        if (null !== $this->onOpen) {
            ($this->onOpen)($this, $this->sessions);
        }

        return new FakePop3Session($this);
    }

    /** @return list<string> the UIDLs commands of this verb were sent for */
    public function sent(string $verb): array
    {
        $uidls = [];

        foreach ($this->log as $line) {
            if (true === str_starts_with($line, $verb . ' ')) {
                $uidls[] = substr($line, strlen($verb) + 1);
            }
        }

        return $uidls;
    }
}
