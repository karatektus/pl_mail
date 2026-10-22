<?php

declare(strict_types=1);

namespace App\Tests\Domain\DTO\Mail;

use App\Domain\DTO\Mail\RemoteFlagState;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;
use PHPUnit\Framework\TestCase;

/**
 * What a refresh writes back as the row's flags.
 *
 * Gmail and Graph never send a flag list, so the mirror is rebuilt from the two
 * facts they do send (read, starred). That used to drop \Draft: the first time
 * a stored draft was re-read it stopped being one, which the refresh's own
 * comment says it must not do. A provider that does send its flags (IMAP) is
 * still taken at its word, \Draft included or not.
 */
final class RemoteFlagStateTest extends TestCase
{
    public function testARebuiltMirrorKeepsTheDraftFlag(): void
    {
        $draft = $this->message([MessageFlag::DRAFT->value, MessageFlag::SEEN->value]);

        $state = new RemoteFlagState($draft, seen: true, flagged: false);

        self::assertSame(
            MessageFlag::canonicalList([MessageFlag::DRAFT->value, MessageFlag::SEEN->value]),
            $state->storedFlags(),
        );
    }

    /** Presence companion: the flag is carried, not invented. */
    public function testARebuiltMirrorDoesNotMakeADraftOfAnythingElse(): void
    {
        $message = $this->message([MessageFlag::SEEN->value]);

        $state = new RemoteFlagState($message, seen: true, flagged: false);

        self::assertSame([MessageFlag::SEEN->value], $state->storedFlags());
    }

    /** A provider that lists its flags is the authority on them. */
    public function testAProvidersOwnFlagListIsTakenAtItsWord(): void
    {
        $draft = $this->message([MessageFlag::DRAFT->value]);

        $state = new RemoteFlagState($draft, seen: true, flagged: false, flags: ['\\Seen']);

        self::assertSame([MessageFlag::SEEN->value], $state->storedFlags());
    }

    /**
     * @param list<string> $flags
     */
    private function message(array $flags): Message
    {
        $message        = new Message();
        $message->flags = $flags;

        return $message;
    }
}
