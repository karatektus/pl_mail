<?php

declare(strict_types=1);

namespace App\Tests\Service\Pop3;

use App\Infrastructure\Pop3\Pop3Exception;
use App\Infrastructure\Pop3\Pop3Listing;
use App\Infrastructure\Pop3\Pop3RefusedException;
use App\Infrastructure\Pop3\Pop3Session;

/** One session on a FakePop3Server: its own numbering, its own delete marks. */
final class FakePop3Session implements Pop3Session
{
    /** @var array<int, string> number => UIDL, fixed for the session */
    private array $numbering = [];

    /** @var array<string, true> */
    private array $marked = [];

    private bool $open = true;

    public function __construct(private readonly FakePop3Server $server)
    {
        $number = 0;

        foreach (array_keys($server->messages) as $uidl) {
            $this->numbering[++$number] = (string) $uidl;
        }
    }

    public function list(): array
    {
        $listing = [];

        foreach ($this->numbering as $number => $uidl) {
            $listing[] = new Pop3Listing($number, strlen($this->server->messages[$uidl] ?? ''));
        }

        return $listing;
    }

    public function uidl(): array
    {
        return $this->numbering;
    }

    public function top(int $number): string
    {
        $uidl = $this->uidlOf($number);
        $this->server->log[] = 'TOP ' . $uidl;

        if (false === $this->server->offersTop) {
            throw new Pop3RefusedException('-ERR TOP not supported');
        }

        $raw = $this->server->messages[$uidl];
        $end = strpos($raw, "\r\n\r\n");

        return (false === $end ? $raw : substr($raw, 0, $end)) . "\r\n\r\n";
    }

    public function retrieve(int $number): string
    {
        $uidl = $this->uidlOf($number);
        $this->server->log[] = 'RETR ' . $uidl;

        if (true === isset($this->server->refuse[$uidl])) {
            throw new Pop3RefusedException('-ERR cannot read message ' . $number);
        }

        return $this->server->messages[$uidl];
    }

    public function markDeleted(int $number, string $uidl): void
    {
        if ($this->uidlOf($number) !== $uidl) {
            // The bug a stale number would be: DELE of somebody else's mail.
            throw new \LogicException(sprintf('DELE %d names %s, not %s', $number, $this->uidlOf($number), $uidl));
        }

        $this->server->log[] = 'DELE ' . $uidl;
        $this->marked[$uidl] = true;
    }

    public function isMarkedDeleted(string $uidl): bool
    {
        return true === isset($this->marked[$uidl]);
    }

    public function quit(): void
    {
        if (false === $this->open) {
            return;
        }

        $this->server->log[] = 'QUIT';

        foreach (array_keys($this->marked) as $uidl) {
            unset($this->server->messages[$uidl]);
        }

        $this->open = false;
    }

    public function abort(): void
    {
        $this->server->log[] = 'ABORT';
        $this->open = false;
    }

    private function uidlOf(int $number): string
    {
        $uidl = $this->numbering[$number] ?? null;

        if (null === $uidl || false === isset($this->server->messages[$uidl])) {
            throw new Pop3Exception(sprintf('no message %d in this session', $number));
        }

        return $uidl;
    }
}
