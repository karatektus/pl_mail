<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;
use App\Jmap\Method\Mail\EmailSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Tests\Jmap\JmapTestCase;

/**
 * "Email/set" — how every JMAP client creates, updates and destroys mail.
 *
 * Most of what follows is not the happy path. Three of this method's decisions
 * are surprising enough that the Android client documented them after probing a
 * live server, and each one is surprising in a way that costs a client a bug if
 * it assumes the obvious instead:
 *
 * 1. **create always writes a draft**, whatever `mailboxIds` says. There is one
 *    creation path and it is the composer's, so a client cannot inject mail.
 * 2. **destroy adds Trash and removes Inbox**, which for a draft means it stays
 *    in Drafts. Right for received mail, startling for a draft.
 * 3. **`attachments` on an update is accepted and dropped.** That one is a bug,
 *    named as such below, pinned so a fix is a deliberate act.
 *
 * The response shape is asserted throughout rather than "it did not throw": a
 * /set answers per-id maps, and a client reads `notUpdated[id].type` to decide
 * what to do next.
 */
final class EmailSetMethodTest extends JmapTestCase
{
    private EmailSetMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(EmailSetMethod::class);
    }

    public function testItIsNamedEmailSet(): void
    {
        self::assertSame('Email/set', $this->method->name());
    }

    // ── create is the composer, and only the composer ─────────────────────

    /**
     * The documented surprise: a create names Inbox and lands in Drafts.
     *
     * JmapDraftWriter is the only creation path this method has, so "create an
     * Email" means "save a draft" and nothing else. A client that believed
     * `mailboxIds` would think it had injected received mail, and would then be
     * unable to explain why the message carries `$draft`.
     */
    public function testCreateFilesIntoDraftsWhateverMailboxIdsSays(): void
    {
        $inboxId = $this->mailboxIdFor(LabelRole::Inbox);

        $result = $this->handle([
            'create' => ['c1' => ['subject' => 'Filed where?', 'mailboxIds' => [$inboxId => true]]],
        ]);

        $message = $this->messageFrom($result, 'c1');

        self::assertSame(['drafts'], $this->rolesOn($message));
        self::assertTrue($message->hasFlag(MessageFlag::DRAFT));
    }

    /**
     * The spec requires the server-set properties back, because the client
     * cannot compute any of them and needs the id to do anything at all.
     */
    public function testCreateReturnsTheServerSetProperties(): void
    {
        $result = $this->handle(['create' => ['c1' => ['subject' => 'Hello']]]);

        $created = (array) $result['created'];

        self::assertArrayHasKey('c1', $created);
        self::assertArrayHasKey('id', $created['c1']);
        self::assertSame('m-'.$created['c1']['id'], $created['c1']['blobId']);
        self::assertNotNull($created['c1']['threadId'], 'a draft is threaded on creation');
        self::assertArrayHasKey('size', $created['c1']);
    }

    /** "#creationId" only resolves if the creation was recorded on the context. */
    public function testCreateRecordsTheCreationIdForLaterCallsInTheSameRequest(): void
    {
        $context = $this->context();

        $result = $this->method->handle(
            ['accountId' => $this->accountId(), 'create' => ['c1' => ['subject' => 'Referable']]],
            $context,
        );

        self::assertSame(
            ((array) $result['created'])['c1']['id'],
            $context->resolveId('#c1'),
        );
    }

    public function testCreateStoresTheHtmlBodyFromBodyValues(): void
    {
        $result = $this->handle([
            'create' => ['c1' => [
                'subject' => 'With a body',
                'htmlBody' => [['partId' => 'h1', 'type' => 'text/html']],
                'bodyValues' => ['h1' => ['value' => '<p>Written on the phone</p>']],
            ]],
        ]);

        self::assertStringContainsString('Written on the phone', (string) $this->messageFrom($result, 'c1')->bodyHtml);
    }

    /**
     * A text-only composer sends no HTML, and its text must not be able to
     * become markup on the way in.
     */
    public function testATextOnlyBodyIsEscapedIntoHtml(): void
    {
        $result = $this->handle([
            'create' => ['c1' => [
                'textBody' => [['partId' => 't1', 'type' => 'text/plain']],
                'bodyValues' => ['t1' => ['value' => '<script>alert(1)</script>']],
            ]],
        ]);

        $html = (string) $this->messageFrom($result, 'c1')->bodyHtml;

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreateTranslatesJmapAddressesIntoStoredOnes(): void
    {
        $result = $this->handle([
            'create' => ['c1' => ['to' => [['name' => 'Ada', 'email' => 'ada@example.test']]]],
        ]);

        self::assertSame(
            [['name' => 'Ada', 'address' => 'ada@example.test']],
            $this->messageFrom($result, 'c1')->toAddresses,
        );
    }

    /**
     * One bad create must not take the others down with it — the per-id maps
     * exist precisely so a batch reports partial success.
     */
    public function testABadCreateIsReportedPerIdAndTheOthersStillLand(): void
    {
        $result = $this->handle([
            'create' => [
                'bad' => ['to' => 'not-an-array-of-addresses'],
                'good' => ['subject' => 'Fine'],
            ],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['bad']['type']);
        self::assertArrayHasKey('good', (array) $result['created']);
    }

    public function testACreateThatIsNotAnObjectIsRefusedForThatIdAlone(): void
    {
        $result = $this->handle(['create' => ['c1' => 'a string']]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['c1']['type']);
    }

    /** A malformed "create" argument is a request-level mistake, not a per-id one. */
    public function testANonObjectCreateArgumentFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['create' => 'not a map at all']);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function testUpdateAppliesAKeywordPatchAndReportsNull(): void
    {
        $message = $this->receivedMessage();

        $result = $this->handle([
            'update' => [(string) $message->id => ['keywords/$seen' => true]],
        ]);

        self::assertSame([(string) $message->id => null], (array) $result['updated']);
        self::assertNotNull($message->seenAt);
    }

    /**
     * `$draft` is in the IMAP flags mirror, which the sync layer owns. Clearing
     * it is accepted and ignored rather than refused, because that is exactly
     * what EmailSubmission/set's onSuccessUpdateEmail sends on every send.
     *
     * The cost is that a client cannot turn a draft into received mail, which
     * is the point — but it is told the write succeeded, so this is pinned as
     * the deliberate half of the trade rather than left to be rediscovered.
     */
    public function testTheDraftKeywordCannotBeRemovedByAKeywordsPatch(): void
    {
        $message = $this->draftMessage();

        $result = $this->handle([
            // An empty JSON object decodes to an empty PHP array, which is what
            // "replace the whole keyword map with nothing" arrives as.
            'update' => [(string) $message->id => ['keywords' => []]],
        ]);

        self::assertArrayHasKey((string) $message->id, (array) $result['updated']);
        self::assertTrue($message->hasFlag(MessageFlag::DRAFT), '$draft was cleared after all');
    }

    /**
     * Removing the last mailbox is refused: an Email with no mailbox is
     * reachable from no list, which is a delete by another name and this
     * method has no delete.
     */
    public function testRemovingTheLastMailboxIsRefused(): void
    {
        $message = $this->receivedMessage();
        $inboxId = $this->mailboxIdFor(LabelRole::Inbox);

        $result = $this->handle([
            'update' => [(string) $message->id => ['mailboxIds/'.$inboxId => null]],
        ]);

        $error = ((array) $result['notUpdated'])[(string) $message->id];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('at least one Mailbox', $error['description']);
        self::assertSame(['inbox'], $this->rolesOn($message), 'the message was mutated before the refusal');
    }

    public function testAnUnknownEmailIsNotUpdated(): void
    {
        $result = $this->handle(['update' => ['999999' => ['keywords/$seen' => true]]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    /**
     * A creation-id key in an update map does NOT resolve, unlike every other
     * /set method here — Identity, Mailbox and PushSubscription all call
     * JmapContext::resolveId() and this one does not.
     *
     * Pinned as the current answer rather than endorsed. It is an
     * inconsistency, and a client that wrote a draft and patched it by
     * "#creationId" in the same request gets notFound for a message that
     * demonstrably exists.
     */
    public function testACreationIdInAnUpdateMapIsNotFound(): void
    {
        $context = $this->context();

        $result = $this->method->handle(
            [
                'accountId' => $this->accountId(),
                'create' => ['c1' => ['subject' => 'Just made']],
                'update' => ['#c1' => ['keywords/$seen' => true]],
            ],
            $context,
        );

        self::assertArrayHasKey('c1', (array) $result['created']);
        self::assertSame('notFound', ((array) $result['notUpdated'])['#c1']['type']);
    }

    public function testAPatchThatIsNotAnObjectIsAnInvalidPatch(): void
    {
        $message = $this->receivedMessage();

        $result = $this->handle(['update' => [(string) $message->id => 'nope']]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[(string) $message->id]['type']);
    }

    public function testAnUnknownPropertyIsRefusedForThatEmail(): void
    {
        $message = $this->receivedMessage();

        $result = $this->handle([
            'update' => [(string) $message->id => ['receivedAt' => '2020-01-01T00:00:00Z']],
        ]);

        self::assertSame('invalidPatch', ((array) $result['notUpdated'])[(string) $message->id]['type']);
    }

    // ── the attachments bug ───────────────────────────────────────────────

    /**
     * A KNOWN BUG, pinned as it currently behaves.
     *
     * `attachments` is in EmailPatchApplier::DRAFT_PROPERTIES, so the patch is
     * accepted; JmapDraftWriter::update() never reads the key, so nothing is
     * stored. The call answers `updated` and the attachment is gone, which is
     * the worst available outcome — a rejection would at least let the client
     * fall back.
     *
     * Not fixed here because fixing it is a production change with a shape to
     * decide (store it, as create does, or refuse the property), and it is
     * documented as an open ask in the Android client's SERVER_REQUESTS.md.
     * WHEN THAT IS FIXED, THIS TEST MUST FAIL — that is what it is for.
     */
    public function testAttachmentsOnAnUpdateAreAcceptedAndSilentlyDropped(): void
    {
        $message = $this->draftMessage();

        $result = $this->handle([
            'update' => [(string) $message->id => [
                'subject' => 'Now with a file',
                'attachments' => [['blobId' => 'u-999999', 'name' => 'notes.txt', 'type' => 'text/plain']],
            ]],
        ]);

        self::assertArrayHasKey((string) $message->id, (array) $result['updated']);
        self::assertSame('Now with a file', $message->subject, 'the rest of the patch did apply');
        self::assertCount(0, $message->messageParts);
        self::assertFalse((bool) $message->hasAttachments);
    }

    /**
     * The same blobId on a create is refused, which is what makes the entry
     * above a bug rather than a policy: the two paths disagree about whether
     * an unresolvable blob is an error.
     */
    public function testTheSameUnresolvableBlobIsRefusedOnACreate(): void
    {
        $result = $this->handle([
            'create' => ['c1' => [
                'subject' => 'Now with a file',
                'attachments' => [['blobId' => 'u-999999', 'name' => 'notes.txt']],
            ]],
        ]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['c1']['type']);
    }

    // ── destroy is a move to Trash ────────────────────────────────────────

    public function testDestroyMovesReceivedMailToTrashAndOutOfTheInbox(): void
    {
        $message = $this->receivedMessage();

        $result = $this->handle(['destroy' => [(string) $message->id]]);

        self::assertSame([(string) $message->id], $result['destroyed']);
        self::assertSame(['trash'], $this->rolesOn($message));
    }

    /**
     * The documented surprise: destroying a draft leaves it in Drafts.
     *
     * destroy adds Trash and removes *Inbox*, and a draft never had Inbox, so
     * it comes back in both Drafts and Trash and keeps appearing in the drafts
     * list. Deliberate — "destroy means move to Trash" is a product rule — but
     * the obvious call does the wrong thing, so a client discards a draft with
     * an explicit mailboxIds patch instead.
     */
    public function testDestroyingADraftLeavesItInDrafts(): void
    {
        $message = $this->draftMessage();

        $result = $this->handle(['destroy' => [(string) $message->id]]);

        self::assertSame([(string) $message->id], $result['destroyed']);
        self::assertSame(['drafts', 'trash'], $this->rolesOn($message));
    }

    /** The row survives, because the provider still holds the mail. */
    public function testDestroyKeepsTheRow(): void
    {
        $message = $this->receivedMessage();
        $id = (int) $message->id;

        $this->handle(['destroy' => [(string) $id]]);
        $this->em->clear();

        self::assertNotNull($this->em->find(\App\Entity\Mail\Message::class, $id));
    }

    public function testAnUnknownEmailIsNotDestroyed(): void
    {
        $result = $this->handle(['destroy' => ['999999']]);

        self::assertSame('notFound', ((array) $result['notDestroyed'])['999999']['type']);
    }

    /** Guessing an id from another account must reach nothing. */
    public function testANonNumericIdIsNotFoundRatherThanAnError(): void
    {
        $result = $this->handle(['destroy' => ['../../etc/passwd']]);

        self::assertSame('notFound', ((array) $result['notDestroyed'])['../../etc/passwd']['type']);
    }

    // ── state ─────────────────────────────────────────────────────────────

    /**
     * ifInState is a client's only defence against a lost update, so a stale
     * token has to fail the whole call rather than a single id.
     */
    public function testAStaleIfInStateFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle([
            'ifInState' => 'definitely-not-the-current-state',
            'update' => [],
        ]);
    }

    public function testACreateAdvancesTheEmailState(): void
    {
        $result = $this->handle(['create' => ['c1' => ['subject' => 'Moves the state']]]);

        self::assertNotSame($result['oldState'], $result['newState']);
    }

    /** A call that changes nothing must not move the state token. */
    public function testANoOpCallLeavesTheStateWhereItWas(): void
    {
        $result = $this->handle([]);

        self::assertSame($result['oldState'], $result['newState']);
    }

    /**
     * Empty JMAP maps must serialise as {} and empty lists as [], which is
     * why the method returns \stdClass rather than [] for the maps — json_encode
     * turns an empty PHP array into "[]" and clients typed against the spec
     * reject that.
     */
    public function testEmptyResultMapsSerialiseAsObjectsAndDestroyedAsAList(): void
    {
        $encoded = json_encode($this->handle([]), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"created":{}', $encoded);
        self::assertStringContainsString('"notCreated":{}', $encoded);
        self::assertStringContainsString('"updated":{}', $encoded);
        self::assertStringContainsString('"notUpdated":{}', $encoded);
        self::assertStringContainsString('"notDestroyed":{}', $encoded);
        self::assertStringContainsString('"destroyed":[]', $encoded);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments, ?JmapContext $context = null): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => $this->accountId()],
            $context ?? $this->context(),
        );
    }

    /**
     * @param array<string,mixed> $result
     */
    private function messageFrom(array $result, string $creationId): \App\Entity\Mail\Message
    {
        $created = (array) $result['created'];

        self::assertArrayHasKey($creationId, $created, 'the create was refused: '.json_encode($result['notCreated']));

        $message = $this->em->find(\App\Entity\Mail\Message::class, (int) $created[$creationId]['id']);

        self::assertNotNull($message);

        return $message;
    }

    /**
     * A draft created over JMAP has to show up in Drafts, which sounds obvious
     * and was not true. ThreadLabelSynchronizer rebuilds a thread's labels from
     * the messages the thread can see, the threader attaches a message by its
     * owning side only, so the draft was invisible to the rebuild and the
     * Drafts label it had just been given was taken straight back off.
     */
    public function testADraftCreatedOverJmapKeepsItsThreadInDrafts(): void
    {
        $result = $this->handle(['create' => ['d1' => [
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Drafts) => true],
            'subject'    => 'Kept in drafts',
            'keywords'   => ['$draft' => true],
        ]]]);

        self::assertArrayHasKey('d1', (array) $result['created'], json_encode($result['notCreated'] ?? []));

        $message = $this->em->find(Message::class, (int) ((array) $result['created'])['d1']['id']);
        self::assertNotNull($message);
        $thread  = $message->thread;

        self::assertNotNull($thread, 'the draft was not threaded at all');

        $roles = [];

        foreach ($thread->labels as $label) {
            $roles[] = $label->role?->value;
        }

        self::assertContains('drafts', $roles, 'the thread lost the Drafts label it was just given');
    }

}
