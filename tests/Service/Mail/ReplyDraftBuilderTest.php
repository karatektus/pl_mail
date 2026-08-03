<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Service\Mail\ReplyDraftBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What a reply *is*, pinned away from the controller it used to live in.
 *
 * These matter beyond the extraction: JmapDraftWriter answers the same question
 * for every non-browser client and its docblock admits to being a copy. Whatever
 * the two are supposed to agree on has to be written down somewhere before it
 * can be shared, and this is that.
 */
final class ReplyDraftBuilderTest extends TestCase
{
    private ReplyDraftBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ReplyDraftBuilder();
    }

    // ── addressing ───────────────────────────────────────────────────────────

    public function testAReplyGoesBackToTheSenderAlone(): void
    {
        $draft = $this->builder->reply($this->original(), $this->account());

        self::assertSame([['name' => 'Katharina', 'address' => 'k@example.test']], $draft->toAddresses);
        self::assertSame([], $draft->ccAddresses);
    }

    public function testReplyAllCopiesEveryoneElseIn(): void
    {
        $draft = $this->builder->reply($this->original(), $this->account(), replyAll: true);

        self::assertSame(
            [['name' => 'Tanja', 'address' => 'tanja@example.test'], ['name' => '', 'address' => 'cc@example.test']],
            $draft->ccAddresses,
        );
    }

    /**
     * The one case that makes reply-all worth testing: answering everyone must
     * not put the sender in their own Cc, and a header spells an address in
     * whatever case it likes. `ownedAddresses` lowercases, so the comparison
     * has to as well — this fails if either side stops.
     */
    public function testReplyAllLeavesTheSenderOutWhateverCaseTheHeaderUsed(): void
    {
        $original                = $this->original();
        $original->toAddresses[] = ['name' => 'Me', 'address' => 'ME@Example.TEST'];

        $draft = $this->builder->reply($original, $this->account(), replyAll: true);

        $addresses = array_map(strtolower(...), array_column($draft->ccAddresses, 'address'));
        self::assertNotContains('me@example.test', $addresses, 'the sender was copied on their own reply');
    }

    public function testAForwardIsAddressedToNobody(): void
    {
        self::assertSame([], $this->builder->forward($this->original())->toAddresses);
    }

    // ── subject ──────────────────────────────────────────────────────────────

    public function testAReplyPrefixesTheSubject(): void
    {
        self::assertSame('Re: Interview', $this->builder->reply($this->original(), $this->account())->subject);
    }

    public function testAReplyToAReplyDoesNotStackPrefixes(): void
    {
        $original          = $this->original();
        $original->subject = 'Re: Interview';

        self::assertSame('Re: Interview', $this->builder->reply($original, $this->account())->subject);
    }

    /**
     * Forwarding a reply is a different act from replying again, so unlike Re
     * the Fwd prefix stacks. "Fwd: Re: x" is information, not noise.
     */
    public function testForwardingAReplyKeepsBothPrefixes(): void
    {
        $original          = $this->original();
        $original->subject = 'Re: Interview';

        self::assertSame('Fwd: Re: Interview', $this->builder->forward($original)->subject);
    }

    public function testAnEmptySubjectStillGetsAPrefix(): void
    {
        $original          = $this->original();
        $original->subject = null;

        self::assertSame('Re: ', $this->builder->reply($original, $this->account())->subject);
    }

    // ── threading ────────────────────────────────────────────────────────────

    public function testAReplyInheritsTheReferenceChainAndAddsTheOriginal(): void
    {
        $draft = $this->builder->reply($this->original(), $this->account());

        self::assertSame(['<older@example.test>', '<orig@example.test>'], $draft->references);
        self::assertSame(['<orig@example.test>'], $draft->inReplyTo);
    }

    /**
     * A message synced without a Message-ID would otherwise contribute a null
     * to the chain, which is worse than a short chain.
     */
    public function testAMessageWithNoIdContributesNothingToTheChain(): void
    {
        $original            = $this->original();
        $original->messageId = null;

        $draft = $this->builder->reply($original, $this->account());

        self::assertSame(['<older@example.test>'], $draft->references);
        self::assertSame([], $draft->inReplyTo);
    }

    public function testAReplyStaysInTheConversationAndAForwardStartsOne(): void
    {
        $thread             = new MessageThread();
        $original           = $this->original();
        $original->thread   = $thread;

        self::assertSame($thread, $this->builder->reply($original, $this->account())->thread);
        self::assertNull($this->builder->forward($original)->thread, 'a forward joined the thread it quotes');
    }

    /**
     * The first autosave of a reply POSTs to a route with no message id, so
     * the server builds a brand new Message and has to put it back where the
     * reply belongs. Without this the reply arrived as a conversation of its
     * own, addressed to the right person and threaded nowhere.
     */
    public function testAReplyRebuiltByAnAutosaveIsPutBackIntoTheConversation(): void
    {
        $thread           = new MessageThread();
        $original         = $this->original();
        $original->thread = $thread;

        $draft = new Message();

        $this->builder->linkToOriginal($draft, $original);

        self::assertSame($thread, $draft->thread);
        self::assertSame(['<orig@example.test>'], $draft->inReplyTo);
        self::assertSame(['<older@example.test>', '<orig@example.test>'], $draft->references);
    }

    /**
     * A draft that already names what it answers keeps its own chain: it was
     * linked when the window opened, and re-deriving it is at best a no-op.
     */
    public function testADraftThatAlreadyNamesWhatItAnswersIsLeftAlone(): void
    {
        $draft             = new Message();
        $draft->inReplyTo  = ['<something-else@example.test>'];
        $draft->references = ['<something-else@example.test>'];

        $this->builder->linkToOriginal($draft, $this->original());

        self::assertSame(['<something-else@example.test>'], $draft->inReplyTo);
        self::assertSame(['<something-else@example.test>'], $draft->references);
    }

    // ── quoting ──────────────────────────────────────────────────────────────

    /**
     * data-quoted marks the whole block so the editor can collapse it and the
     * autosave guard can tell the user's writing from the mail they answer.
     */
    public function testTheQuotedBlockIsMarkedForTheEditor(): void
    {
        self::assertStringContainsString(
            'data-quoted="1"',
            (string) $this->builder->reply($this->original(), $this->account())->bodyHtml,
        );
    }

    public function testAPlainTextOriginalIsStillQuoted(): void
    {
        $original           = $this->original();
        $original->bodyHtml = null;
        $original->bodyText = "line one\nline two";

        $body = (string) $this->builder->reply($original, $this->account())->bodyHtml;

        self::assertStringContainsString('line one', $body);
        self::assertStringContainsString('<br', $body, 'newlines were dropped rather than broken');
    }

    public function testAQuotedSenderNameCannotInjectMarkup(): void
    {
        $original           = $this->original();
        $original->fromName = '<script>alert(1)</script>';

        $body = (string) $this->builder->reply($original, $this->account())->bodyHtml;

        self::assertStringNotContainsString('<script>', $body);
    }

    public function testAForwardCarriesTheOriginalHeadersIntoTheBody(): void
    {
        $body = (string) $this->builder->forward($this->original())->bodyHtml;

        self::assertStringContainsString('Forwarded message', $body);
        self::assertStringContainsString('Interview', $body);
        self::assertStringContainsString('tanja@example.test', $body);
    }

    // ── fixture ──────────────────────────────────────────────────────────────

    private function original(): Message
    {
        $message              = new Message();
        $message->account     = $this->account();
        $message->subject     = 'Interview';
        $message->fromName    = 'Katharina';
        $message->fromAddress = 'k@example.test';
        $message->messageId   = '<orig@example.test>';
        $message->references  = ['<older@example.test>'];
        $message->receivedAt  = new DateTimeImmutable('2026-08-03 09:00:00');
        $message->bodyHtml    = '<p>Moin Paul</p>';
        $message->toAddresses = [
            ['name' => 'Me', 'address' => 'me@example.test'],
            ['name' => 'Tanja', 'address' => 'tanja@example.test'],
        ];
        $message->ccAddresses = [['name' => '', 'address' => 'cc@example.test']];

        return $message;
    }

    /** @param list<string> $owned */
    private function account(array $owned = ['me@example.test']): Account
    {
        $account = new Account();
        $account->usr   = new User();
        $account->email = $owned[0];

        return $account;
    }
}
