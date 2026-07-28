<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Repository\MessageRepository;
use App\Repository\MessageThreadRepository;
use App\Service\Imap\MessageThreader;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageThreaderTest extends TestCase
{
    private MessageThreader $threader;

    protected function setUp(): void
    {
        // Both methods under test are pure string handling — the collaborators
        // are stubs purely to satisfy the constructor and are never called.
        $this->threader = new MessageThreader(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageRepository::class),
            $this->createStub(MessageThreadRepository::class),
        );
    }

    #[DataProvider('normalizeSubjectCases')]
    public function testNormalizeSubject(?string $subject, string $expected): void
    {
        self::assertSame($expected, $this->threader->normalizeSubject($subject));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function normalizeSubjectCases(): iterable
    {
        yield 'null'            => [null, ''];
        yield 'plain'           => ['Project update', 'project update'];
        yield 're'              => ['Re: Project update', 'project update'];
        yield 'fwd'             => ['Fwd: Project update', 'project update'];
        yield 'repeated'        => ['Re: Re: Fwd: Project update', 'project update'];
        yield 'no space'        => ['Fwd:Fwd:Project update', 'project update'];
        yield 'spaced colon'    => ['RE : Project update', 'project update'];
        yield 'counted'         => ['Re[2]: Project update', 'project update'];
        yield 'german reply'    => ['AW: Projektstatus', 'projektstatus'];
        yield 'german forward'  => ['WG: Projektstatus', 'projektstatus'];
        yield 'mixed locale'    => ['AW: Re: Projektstatus', 'projektstatus'];
        yield 'surrounding ws'  => ['  Re: Project update  ', 'project update'];
        yield 'prefix in body'  => ['Rewriting the parser', 'rewriting the parser'];
    }

    /**
     * The subject column is TEXT, so normalisation must not cap length —
     * truncating here would silently merge two distinct long subjects.
     */
    public function testNormalizeSubjectDoesNotTruncateLongSubjects(): void
    {
        $subject = 'Re: ' . str_repeat('a', 4000);

        self::assertSame(str_repeat('a', 4000), $this->threader->normalizeSubject($subject));
    }

    #[DataProvider('replyPrefixCases')]
    public function testHasReplyPrefix(?string $subject, bool $expected): void
    {
        self::assertSame($expected, $this->threader->hasReplyPrefix($subject));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function replyPrefixCases(): iterable
    {
        yield 'reply'            => ['Re: Project update', true];
        yield 'lowercase reply'  => ['re: project update', true];
        yield 'forward'          => ['Fwd: Project update', true];
        yield 'german reply'     => ['AW: Projektstatus', true];
        yield 'german forward'   => ['WG: Projektstatus', true];
        yield 'counted reply'    => ['Re[2]: Project update', true];
        yield 'null'             => [null, false];
        yield 'empty'            => ['', false];
        yield 'plain'            => ['Project update', false];
        // The regression this gate exists for: notification subjects repeat
        // verbatim for years and must never merge into one another.
        yield 'amazon order'     => ['Ihre Amazon.de Bestellung', false];
        yield 'amazon shipped'   => ['Ihre Bestellung wurde versandt', false];
        yield 'prefix-like word' => ['Rewriting the parser', false];
        yield 'colon elsewhere'  => ['Reminder: standup at 9', false];
    }
}
