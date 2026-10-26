<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Service\Imap\ImapQuery;
use Illuminate\Support\Collection;
use Webklex\PHPIMAP\Message;

/**
 * ImapQuery with the two calls that need a server stood in for.
 *
 * build() parses the header and body the way the library's make() does —
 * through webklex's own Header, so a header that breaks the parser breaks it
 * here too — but from a string, with no client to reach through. search()
 * answers what the test scripts. Everything ImapQuery decides on top of those
 * two is the real code.
 *
 * Made with newInstanceWithoutConstructor(), because the real constructor asks
 * a connected client for its configuration.
 */
final class ScriptedBuildQuery extends ImapQuery
{
    /** Thrown by every build() when set: a parser failure no repair can mend. */
    public ?\Throwable $buildFailure = null;

    /** What searchOnServer() throws, or null to find $found. */
    public ?\Throwable $searchFailure = null;

    /** @var list<int> */
    public array $found = [];

    /** @var list<string> every raw header build() was handed, in order */
    public array $builtFrom = [];

    public static function scripted(): self
    {
        return new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
    }

    /**
     * make(), which is protected on the library's Query.
     *
     * @param array<int|string, mixed> $flags
     */
    public function makeMessage(int $uid, string $header, string $content = "hello\r\n", array $flags = []): ?Message
    {
        return $this->make($uid, 0, $header, $content, $flags);
    }

    protected function build(int $uid, int $msglist, string $header, string $content, array $flags): Message
    {
        $this->builtFrom[] = $header;

        if (null !== $this->buildFailure) {
            throw $this->buildFailure;
        }

        return Message::fromString(rtrim($header, "\r\n") . "\r\n\r\n" . $content, ImapConnectionFactory::config())->setUid($uid);
    }

    protected function searchOnServer(): Collection
    {
        if (null !== $this->searchFailure) {
            throw $this->searchFailure;
        }

        return new Collection($this->found);
    }
}
