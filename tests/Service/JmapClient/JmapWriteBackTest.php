<?php

declare(strict_types=1);

namespace App\Tests\Service\JmapClient;

use App\Infrastructure\Messaging\Handler\ApplyJmapChangesHandler;
use App\Infrastructure\Messaging\Message\ApplyJmapChangesMessage;
use App\Service\Label\LabelChangePropagator;
use App\Service\Label\LabelResolver;
use App\Tests\Support\Jmap\SyncsAgainstFakeJmapServer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What is done to a message here reaches the JMAP server (#27).
 *
 * Through LabelChangePropagator, as every provider's changes are, so these
 * are the same verbs the UI, the JMAP server and the rules engine use. Each
 * test reads the fake server's Email afterwards: a change that only reached
 * the local row would be undone by the next sync, which is the failure this
 * guards against.
 */
final class JmapWriteBackTest extends KernelTestCase
{
    use SyncsAgainstFakeJmapServer;

    private string $emailId;

    protected function setUp(): void
    {
        $this->bootJmap();

        $this->server->addMailbox('mb-projects', 'Projects');
        $this->emailId = $this->server->deliver('Write me back', ['mb-inbox', 'mb-projects']);

        $this->syncAndImport();
    }

    protected function tearDown(): void
    {
        $this->rollBackJmap();

        parent::tearDown();
    }

    public function testMarkingReadSetsTheSeenKeyword(): void
    {
        $this->propagator()->markRead([$this->only()], true);
        $this->em->flush();

        self::assertSame(1, $this->apply());
        self::assertArrayHasKey('$seen', $this->server->emails[$this->emailId]['keywords']);
        self::assertNull($this->only()->flagsTouchedAt, 'a change the server took is no longer in flight');
    }

    public function testStarringSetsAndClearsTheFlaggedKeyword(): void
    {
        $this->propagator()->star([$this->only()], true);
        $this->apply();
        self::assertArrayHasKey('$flagged', $this->server->emails[$this->emailId]['keywords']);

        $this->propagator()->star([$this->only()], false);
        $this->apply();
        self::assertArrayNotHasKey('$flagged', $this->server->emails[$this->emailId]['keywords']);
    }

    /**
     * The server has no archive mailbox, as many do not. Archiving there must
     * still work: removing the Inbox alone would leave the Email in Projects,
     * but on an account where the Inbox was its only mailbox RFC 8621 refuses
     * an Email with none — so plMail makes the Archive.
     */
    public function testArchivingMakesTheArchiveMailboxWhenTheServerHasNone(): void
    {
        $this->propagator()->archive([$this->only()]);
        $this->apply();

        $mailboxes = array_keys($this->server->emails[$this->emailId]['mailboxIds']);
        $archive   = array_values(array_filter($this->server->mailboxes, static fn (array $box): bool => 'archive' === $box['role']));

        self::assertCount(1, $archive, 'an archive mailbox was made, once');
        self::assertContains($archive[0]['id'], $mailboxes);
        self::assertNotContains('mb-inbox', $mailboxes);
        self::assertContains('mb-projects', $mailboxes, 'a mailbox the change was not about is left alone');
    }

    public function testTrashLeavesTheEmailInTheTrashAlone(): void
    {
        $this->propagator()->trash([$this->only()]);
        $this->apply();

        self::assertSame(['mb-trash'], array_keys($this->server->emails[$this->emailId]['mailboxIds']));
    }

    public function testALabelMadeHereBecomesAMailboxThere(): void
    {
        $label = static::getContainer()->get(LabelResolver::class)->customChain(['Receipts', 'Year 2026'], $this->freshAccount());
        $this->em->flush();

        $this->propagator()->attachLabel([$this->only()], $label);
        $this->apply();

        $byName = array_column($this->server->mailboxes, null, 'name');

        self::assertArrayHasKey('Receipts', $byName);
        self::assertSame($byName['Receipts']['id'], $byName['Year 2026']['parentId'], 'the parent is made first and the child under it');
        self::assertArrayHasKey($byName['Year 2026']['id'], $this->server->emails[$this->emailId]['mailboxIds']);
        self::assertSame($byName['Year 2026']['id'], $label->bindingFor($this->freshAccount())?->jmapMailboxId, 'and the binding remembers it');
    }

    public function testDiscardingADraftDestroysTheEmail(): void
    {
        $this->propagator()->delete([$this->only()]);
        $this->apply();

        self::assertArrayNotHasKey($this->emailId, $this->server->emails);
    }

    private function propagator(): LabelChangePropagator
    {
        return static::getContainer()->get(LabelChangePropagator::class);
    }

    /** Run the queued JMAP change jobs, as the export worker would. */
    private function apply(): int
    {
        $this->em->flush();

        $handler = static::getContainer()->get(ApplyJmapChangesHandler::class);

        return $this->drain('export', ApplyJmapChangesMessage::class, static fn (ApplyJmapChangesMessage $message) => $handler($message));
    }
}
